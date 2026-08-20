<?php

namespace App\Http\Controllers\Api;

use App\Events\LoyaltyCardUpdated;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard marchand : clientèle, validation de tampons et statistiques.
 *
 * Ces données vivaient côté Supabase alors que l'inscription marchand écrit
 * dans Laravel : le dashboard n'y trouvait jamais rien. Tout est ici adossé
 * aux tables `loyalty_cards` / `loyalty_transactions` du commerce authentifié.
 */
class MerchantDashboardController extends Controller
{
    /**
     * GET /api/merchant/clients
     *
     * Clientèle du commerce (une ligne par carte de fidélité).
     * `q` filtre sur le nom/téléphone, `filter` sur l'ancienneté.
     */
    public function clients(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $query = LoyaltyCard::query()
            ->with('client')
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('created_at');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->query('filter') === 'inactive_30d') {
            $query->where(function ($q) {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', now()->subDays(30));
            });
        }

        return response()->json([
            'clients' => $query->get()->map(fn (LoyaltyCard $card) => $this->cardData($card))->all(),
        ]);
    }

    /**
     * GET /api/merchant/clients/{loyaltyCard}
     */
    public function showClient(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorizeCard($request, $loyaltyCard);
        $loyaltyCard->load('client');

        return response()->json(['client' => $this->cardData($loyaltyCard)]);
    }

    /**
     * GET /api/merchant/clients/lookup?code=
     *
     * Retrouve une carte du commerce depuis son `card_code`, son `qr_token`
     * ou le `uuid` du client — les trois identifiants que l'écran de
     * validation peut recevoir (saisie manuelle ou scan).
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $restaurant = $this->restaurant($request);
        $code = trim($request->query('code'));

        // `qr_token` et `clients.uuid` sont des colonnes UUID : les comparer à
        // un code de carte (ex. « QDA9D363 ») ferait échouer la requête
        // Postgres sur un cast invalide, pas seulement renvoyer 0 ligne.
        $isUuid = (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $code,
        );

        $card = LoyaltyCard::query()
            ->with('client')
            ->where('restaurant_id', $restaurant->id)
            ->where(function ($q) use ($code, $isUuid) {
                $q->where('card_code', $code);
                if ($isUuid) {
                    $q->orWhere('qr_token', $code)
                        ->orWhereHas('client', fn ($c) => $c->where('uuid', $code));
                }
            })
            ->first();

        if (! $card) {
            return response()->json([
                'message' => 'Aucun client de votre commerce ne correspond à ce code.',
            ], 404);
        }

        return response()->json(['client' => $this->cardData($card)]);
    }

    /** FCFA par point en mode "Achat", si le programme n'a pas sa propre valeur. */
    private const DEFAULT_FCFA_PER_POINT = 500;

    /**
     * POST /api/merchant/clients/{loyaltyCard}/stamps
     *
     * Accorde un tampon (ou des points) et débloque la récompense dès que
     * l'objectif du programme est atteint. La transaction est journalisée
     * dans `loyalty_transactions` — c'est elle qui alimente les statistiques.
     *
     * En mode "Achat" (`spend`), le gain n'est plus un forfait de 1 : le
     * marchand saisit le montant réel (`amount_fcfa`), converti en points au
     * taux du programme (`config.fcfa_per_point`, 500 par défaut — c'est le
     * taux déjà annoncé au marchand pendant l'onboarding).
     */
    public function addStamp(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $restaurant = $this->authorizeCard($request, $loyaltyCard);

        if (! in_array($loyaltyCard->status, ['active', 'reward_available'], true)) {
            return response()->json([
                'message' => 'Cette carte n\'est plus active.',
            ], 422);
        }

        $program = $restaurant->loyaltyProgram;
        if (! $program) {
            return response()->json([
                'message' => 'Aucun programme de fidélité actif.',
            ], 422);
        }

        // Anti double-validation : deux scans/taps rapprochés sur la même
        // carte (mauvais réseau, double-tap) ne doivent pas accorder deux
        // fois le gain. Verrou court, libéré à la fin du bloc.
        $lock = Cache::lock("add-stamp:{$loyaltyCard->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Validation déjà en cours pour cette carte, réessayez dans un instant.',
            ], 409);
        }

        try {
            // Chemin de code structurellement séparé (pas une branche
            // conditionnelle dans `grantStampOrPoints`) : le cashback n'a ni
            // cycle ni objectif, aucun reset ne doit jamais pouvoir s'y
            // appliquer, même par erreur future dans l'autre méthode.
            return $program->type === 'cashback'
                ? $this->grantCashback($request, $restaurant, $loyaltyCard, $program)
                : $this->grantStampOrPoints($request, $restaurant, $loyaltyCard, $program);
        } finally {
            $lock->release();
        }
    }

    /**
     * Mode Cashback : crédite un pourcentage du montant de l'achat en solde
     * utilisable — pas de cycle, pas de récompense, pas de reset.
     */
    private function grantCashback(
        Request $request,
        Restaurant $restaurant,
        LoyaltyCard $loyaltyCard,
        \App\Models\LoyaltyProgram $program,
    ): JsonResponse {
        $request->validate([
            'amount_fcfa' => ['required', 'numeric', 'min:1'],
        ], [
            'amount_fcfa.required' => 'Le montant de l\'achat est requis pour ce programme.',
        ]);

        $amountFcfa = (float) $request->input('amount_fcfa');
        $percentage = (float) ($program->config['cashback_percentage'] ?? 0);
        $earnedFcfa = round($amountFcfa * $percentage / 100, 2);

        DB::transaction(function () use ($loyaltyCard, $earnedFcfa, $amountFcfa) {
            $loyaltyCard->update([
                'cashback_balance_fcfa' => $loyaltyCard->cashback_balance_fcfa + $earnedFcfa,
                'last_activity_at'      => now(),
            ]);

            DB::table('loyalty_transactions')->insert([
                'loyalty_card_id'       => $loyaltyCard->id,
                'type'                  => 'cashback_earn',
                'value'                 => $earnedFcfa,
                'montant_commande_fcfa' => $amountFcfa,
                'validation_method'     => 'merchant_app',
                'status'                => 'valid',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        });

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);
        LoyaltyCardUpdated::dispatch($freshCard);

        return response()->json([
            'message'         => number_format($earnedFcfa, 0, ',', ' ') . ' FCFA de cashback crédités.',
            'reward_unlocked' => false,
            'rewards_unlocked_count' => 0,
            'cashback_earned' => $earnedFcfa,
            'client'          => $this->cardData($freshCard),
        ]);
    }

    /**
     * POST /api/merchant/clients/{loyaltyCard}/redeem-cashback
     *
     * Utilisation du solde cashback comme réduction sur un achat en cours —
     * plafonnée au solde ET, si configuré, à un pourcentage du montant de
     * l'achat (`cashback_redeem_cap_percent`).
     */
    public function redeemCashback(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $restaurant = $this->authorizeCard($request, $loyaltyCard);
        $program = $restaurant->loyaltyProgram;

        if (! $program || $program->type !== 'cashback') {
            return response()->json([
                'message' => 'Ce programme ne gère pas de cashback.',
            ], 422);
        }

        $request->validate([
            'amount_fcfa'        => ['required', 'numeric', 'min:1'],
            'redeem_amount_fcfa' => ['required', 'numeric', 'min:1'],
        ], [
            'amount_fcfa.required'        => 'Le montant de l\'achat est requis.',
            'redeem_amount_fcfa.required' => 'Le montant de cashback à utiliser est requis.',
        ]);

        $amountFcfa = (float) $request->input('amount_fcfa');
        $redeemAmount = (float) $request->input('redeem_amount_fcfa');
        $balance = (float) $loyaltyCard->cashback_balance_fcfa;

        if ($redeemAmount > $balance) {
            return response()->json([
                'message' => 'Solde cashback insuffisant.',
            ], 422);
        }

        $capPercent = $program->config['cashback_redeem_cap_percent'] ?? null;
        if ($capPercent !== null) {
            $maxAllowed = round($amountFcfa * ((float) $capPercent) / 100, 2);
            if ($redeemAmount > $maxAllowed) {
                return response()->json([
                    'message' => "Plafond dépassé : maximum {$maxAllowed} FCFA de cashback pour cet achat ({$capPercent}%).",
                ], 422);
            }
        }

        $lock = Cache::lock("redeem-cashback:{$loyaltyCard->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Validation déjà en cours pour cette carte, réessayez dans un instant.',
            ], 409);
        }

        try {
            DB::transaction(function () use ($loyaltyCard, $redeemAmount, $amountFcfa) {
                $loyaltyCard->update([
                    'cashback_balance_fcfa' => $loyaltyCard->cashback_balance_fcfa - $redeemAmount,
                    'last_activity_at'      => now(),
                ]);

                DB::table('loyalty_transactions')->insert([
                    'loyalty_card_id'       => $loyaltyCard->id,
                    'type'                  => 'cashback_redeem',
                    'value'                 => $redeemAmount,
                    'montant_commande_fcfa' => $amountFcfa,
                    'validation_method'     => 'merchant_app',
                    'status'                => 'valid',
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
            });
        } finally {
            $lock->release();
        }

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);
        LoyaltyCardUpdated::dispatch($freshCard);

        return response()->json([
            'message' => 'Cashback utilisé.',
            'client'  => $this->cardData($freshCard),
        ]);
    }

    private function grantStampOrPoints(
        Request $request,
        Restaurant $restaurant,
        LoyaltyCard $loyaltyCard,
        \App\Models\LoyaltyProgram $program,
    ): JsonResponse {
        $isSpendMode = $program->type === 'spend';
        $amountFcfa = null;
        $earned = 1;

        if ($isSpendMode) {
            $request->validate([
                'amount_fcfa' => ['required', 'numeric', 'min:1'],
            ], [
                'amount_fcfa.required' => 'Le montant de l\'achat est requis pour ce programme.',
            ]);

            $amountFcfa = (float) $request->input('amount_fcfa');
            $rate = (int) ($program->config['fcfa_per_point'] ?? self::DEFAULT_FCFA_PER_POINT);
            $earned = intdiv((int) $amountFcfa, $rate);

            if ($earned < 1) {
                return response()->json([
                    'message' => "Montant insuffisant : il faut au moins {$rate} FCFA pour gagner un point.",
                ], 422);
            }
        }

        $goal = (int) ($program->config['goal'] ?? 10);
        $progress = $loyaltyCard->progress ?? [];
        $current = (int) ($progress['stamps_current'] ?? 0) + $earned;

        // Un seul gros achat peut franchir l'objectif plusieurs fois d'un
        // coup (ex. objectif 500, +1050 points) : chaque franchissement
        // débloque sa propre récompense, et le reste doit être conservé
        // pour le nouveau cycle plutôt que d'être perdu à un simple reset.
        $cyclesCompleted = 0;
        if ($goal > 0) {
            while ($current >= $goal) {
                $current -= $goal;
                $cyclesCompleted++;
            }
        }
        $rewardUnlocked = $cyclesCompleted > 0;

        $restaurantId = $restaurant->id;
        $rewardTitle = $program->config['reward_description'] ?? 'Récompense débloquée';
        $validityDays = $program->config['reward_validity_days'] ?? null;

        DB::transaction(function () use (
            $loyaltyCard, $progress, $current, $rewardUnlocked, $cyclesCompleted, $goal,
            $earned, $amountFcfa, $restaurantId, $rewardTitle, $validityDays,
        ) {
            $loyaltyCard->update([
                'progress'         => array_merge($progress, ['stamps_current' => $current]),
                'status'           => $rewardUnlocked ? 'reward_available' : 'active',
                'last_activity_at' => now(),
            ]);

            DB::table('loyalty_transactions')->insert([
                'loyalty_card_id'        => $loyaltyCard->id,
                'type'                   => 'stamp',
                'value'                  => $earned,
                'montant_commande_fcfa'  => $amountFcfa,
                'validation_method'      => 'merchant_app',
                'status'                 => 'valid',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // Signal durable, indépendant du compteur `progress` (qui vient
            // d'être remis à `current`) : compter les cycles complétés à vie
            // ne doit jamais dépendre d'un compteur remis à zéro plus tard.
            for ($i = 0; $i < $cyclesCompleted; $i++) {
                DB::table('loyalty_transactions')->insert([
                    'loyalty_card_id'   => $loyaltyCard->id,
                    'type'              => 'cycle_completed',
                    'value'             => $goal,
                    'validation_method' => 'merchant_app',
                    'status'            => 'valid',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                // Une récompense tracée par franchissement — titre figé au
                // moment du déblocage (jamais résolu depuis `config` plus
                // tard, qui peut avoir changé entretemps).
                \App\Models\LoyaltyReward::create([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'restaurant_id'   => $restaurantId,
                    'title'           => $rewardTitle,
                    'unlocked_at'     => now(),
                    'expires_at'      => $validityDays ? now()->addDays((int) $validityDays) : null,
                ]);
            }
        });

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);

        // Diffusion Reverb : le wallet client se met à jour en direct, sans
        // pull-to-refresh (voir routes/channels.php, canal `loyalty.{clientId}`
        // déjà autorisé).
        LoyaltyCardUpdated::dispatch($freshCard);

        $message = match (true) {
            $cyclesCompleted > 1 => "{$cyclesCompleted} récompenses débloquées !",
            $cyclesCompleted === 1 => 'Récompense débloquée !',
            default => $isSpendMode ? "{$earned} point(s) accordé(s)." : 'Tampon accordé.',
        };

        return response()->json([
            'message'               => $message,
            'reward_unlocked'       => $rewardUnlocked,
            'rewards_unlocked_count' => $cyclesCompleted,
            'points_earned'         => $earned,
            'client'                => $this->cardData($freshCard),
        ]);
    }

    /**
     * GET /api/merchant/rewards/lookup?token=
     *
     * Résout le QR unique scanné depuis l'app client (préfixe `MIVAFID-REWARD:`
     * reconnu côté Flutter avant l'appel — ce endpoint ne reçoit que le jeton).
     */
    public function lookupReward(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);
        $restaurant = $this->restaurant($request);

        $reward = \App\Models\LoyaltyReward::query()
            ->with('loyaltyCard.client')
            ->where('restaurant_id', $restaurant->id)
            ->where('redeem_token', trim($request->query('token')))
            ->first();

        if (! $reward) {
            return response()->json([
                'message' => 'Aucune récompense de votre commerce ne correspond à ce code.',
            ], 404);
        }

        return response()->json(['reward' => $this->rewardData($reward)]);
    }

    /**
     * POST /api/merchant/rewards/{loyaltyReward}/redeem
     */
    public function redeemReward(Request $request, \App\Models\LoyaltyReward $loyaltyReward): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        abort_if($loyaltyReward->restaurant_id !== $restaurant->id, 403, 'Cette récompense ne concerne pas votre commerce.');

        $lock = Cache::lock("redeem-reward:{$loyaltyReward->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Validation déjà en cours pour cette récompense, réessayez dans un instant.',
            ], 409);
        }

        try {
            if (! $loyaltyReward->isRedeemable()) {
                return response()->json([
                    'message' => $loyaltyReward->status !== 'available'
                        ? 'Cette récompense a déjà été utilisée ou annulée.'
                        : 'Cette récompense a expiré.',
                ], 422);
            }

            $loyaltyReward->update([
                'status'  => 'used',
                'used_at' => now(),
            ]);
        } finally {
            $lock->release();
        }

        return response()->json([
            'message' => 'Récompense validée.',
            'reward'  => $this->rewardData($loyaltyReward->fresh()->load('loyaltyCard.client')),
        ]);
    }

    /**
     * POST /api/merchant/rewards/{loyaltyReward}/cancel
     */
    public function cancelReward(Request $request, \App\Models\LoyaltyReward $loyaltyReward): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        abort_if($loyaltyReward->restaurant_id !== $restaurant->id, 403, 'Cette récompense ne concerne pas votre commerce.');

        if ($loyaltyReward->status !== 'available') {
            return response()->json([
                'message' => 'Seule une récompense encore disponible peut être annulée.',
            ], 422);
        }

        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $loyaltyReward->update([
            'status'        => 'canceled',
            'canceled_at'   => now(),
            'cancel_reason' => $request->input('reason'),
        ]);

        return response()->json([
            'message' => 'Récompense annulée.',
            'reward'  => $this->rewardData($loyaltyReward->fresh()),
        ]);
    }

    /**
     * GET /api/merchant/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $cardIds = LoyaltyCard::where('restaurant_id', $restaurant->id)->pluck('id');

        $stampsToday = DB::table('loyalty_transactions')
            ->whereIn('loyalty_card_id', $cardIds)
            ->where('status', 'valid')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $recent = LoyaltyCard::query()
            ->with('client')
            ->whereIn('id', $cardIds)
            ->whereNotNull('last_activity_at')
            ->orderByDesc('last_activity_at')
            ->limit(10)
            ->get()
            ->map(fn (LoyaltyCard $card) => [
                'client_name' => $this->clientName($card),
                'action'      => 'Tampon accordé',
                'at'          => $card->last_activity_at?->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'total_clients'   => $cardIds->count(),
            'stamps_today'    => $stampsToday,
            'active_rewards'  => LoyaltyCard::whereIn('id', $cardIds)
                ->where('status', 'reward_available')
                ->count(),
            'recent_activity' => $recent,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Helpers privés
    // ─────────────────────────────────────────────────────────

    private function restaurant(Request $request): Restaurant
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        return $restaurant;
    }

    /** Refuse l'accès à une carte qui n'appartient pas au commerce connecté. */
    private function authorizeCard(Request $request, LoyaltyCard $card): Restaurant
    {
        $restaurant = $this->restaurant($request);

        abort_if($card->restaurant_id !== $restaurant->id, 403, 'Cette carte ne concerne pas votre commerce.');

        return $restaurant;
    }

    private function clientName(LoyaltyCard $card): string
    {
        $client = $card->client;
        if (! $client) {
            return 'Client';
        }

        return trim("{$client->first_name} {$client->last_name}") ?: 'Client';
    }

    private function cardData(LoyaltyCard $card): array
    {
        return [
            'id'               => (string) $card->id,
            'client_id'        => (string) $card->client_id,
            'restaurant_id'    => (string) $card->restaurant_id,
            'card_code'        => $card->card_code,
            'stamps_current'   => (int) ($card->progress['stamps_current'] ?? 0),
            'status'           => $card->status,
            'reward_available' => $card->status === 'reward_available',
            'last_activity_at' => $card->last_activity_at?->toIso8601String(),
            'created_at'       => $card->created_at?->toIso8601String(),
            'client'           => $card->client ? [
                'id'        => (string) $card->client->id,
                'uuid'      => $card->client->uuid,
                'name'      => $this->clientName($card),
                'phone'     => $card->client->phone,
                'email'     => $card->client->email,
                'avatar_url' => $card->client->avatar_url,
            ] : null,
        ];
    }

    private function rewardData(\App\Models\LoyaltyReward $reward): array
    {
        $card = $reward->loyaltyCard;

        return [
            'id'           => (string) $reward->id,
            'title'        => $reward->title,
            'status'       => $reward->status,
            'is_expired'   => $reward->is_expired,
            'unlocked_at'  => $reward->unlocked_at?->toIso8601String(),
            'expires_at'   => $reward->expires_at?->toIso8601String(),
            'used_at'      => $reward->used_at?->toIso8601String(),
            'client'       => $card?->client ? [
                'name'  => $this->clientName($card),
                'phone' => $card->client->phone,
            ] : null,
        ];
    }
}
