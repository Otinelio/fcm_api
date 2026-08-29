<?php

namespace App\Http\Controllers\Api;

use App\Events\LoyaltyCardUpdated;
use App\Events\LoyaltyRewardUpdated;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Restaurant;
use App\Services\Loyalty\LoyaltyTierService;
use App\Services\Referral\ReferralService;
use App\Support\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard marchand : clientèle, validation de tampons et statistiques.
 *
 * Ces données vivaient côté Supabase alors que l'inscription marchand écrit
 * dans Laravel : le dashboard n'y trouvait jamais rien. Tout est ici adossé
 * aux tables `loyalty_cards` / `loyalty_transactions` du commerce authentifié.
 */
class MerchantDashboardController extends Controller
{
    public function __construct(private readonly ReferralService $referralService)
    {
    }

    /**
     * GET /api/merchant/clients
     *
     * Clientèle du commerce (une ligne par carte de fidélité), paginée.
     *
     * Paramètres :
     * - `q` : recherche sur le nom/téléphone ;
     * - `inactive_days` : inactifs depuis N jours (ou jamais actifs) ;
     * - `level` : clé canonique de niveau (`bronze|silver|gold|platinum|custom`) ;
     * - `min_lifetime_cashback` : cashback cumulé à vie >= N FCFA (cashback uniquement) ;
     * - `min_cycles` : ayant terminé le programme au moins N fois ;
     * - `sort` : `activity` (défaut) | `recent` | `oldest` ;
     * - `page` / `per_page` : pagination (per_page plafonné à 100).
     */
    public function clients(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $query = LoyaltyCard::query()
            ->with(['client', 'loyaltyProgram.tiers'])
            ->where('restaurant_id', $restaurant->id);

        if ($search = trim((string) $request->query('q', ''))) {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (($inactiveDays = (int) $request->query('inactive_days', 0)) > 0) {
            $limit = now()->subDays($inactiveDays);
            $query->where(function ($q) use ($limit) {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', $limit);
            });
        }

        if (($minCycles = (int) $request->query('min_cycles', 0)) > 0) {
            $query->where('cycles_completed', '>=', $minCycles);
        }

        match (trim((string) $request->query('sort', 'activity'))) {
            'recent' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            default => $query->orderByRaw('COALESCE(last_activity_at, created_at) DESC'),
        };

        $cards = $query->get();

        // Filtre de niveau en PHP : le niveau courant est résolu depuis les
        // paliers du programme (LoyaltyTierService), ce n'est pas une colonne
        // requêtable en SQL. Les listes restant par-commerce, le volume reste
        // raisonnable.
        if ($levelKey = trim((string) $request->query('level', ''))) {
            $cards = $cards
                ->filter(fn (LoyaltyCard $card) => ($card->level['key'] ?? null) === $levelKey)
                ->values();
        }

        // Segmentation marchand par cashback cumulé à vie (spec §7) —
        // indépendant de la base de progression choisie (`cashback_tier_basis`) :
        // le cumul historique sert toujours de repère pour le marchand, même
        // si l'affichage client suit le solde.
        if (($minLifetimeCashback = (float) $request->query('min_lifetime_cashback', 0)) > 0) {
            $tierService = app(LoyaltyTierService::class);
            $cards = $cards
                ->filter(fn (LoyaltyCard $card) => $tierService->lifetimeCashback($card) >= $minLifetimeCashback)
                ->values();
        }

        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        $perPage = min($perPage, 100);
        $page = max((int) $request->query('page', 1), 1);

        return response()->json([
            'data' => $cards
                ->forPage($page, $perPage)
                ->values()
                ->map(fn (LoyaltyCard $card) => $this->cardData($card))
                ->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($cards->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $cards->count(),
            ],
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

        // Saisie manuelle d'un numéro de téléphone : le marchand peut le
        // taper avec espaces/tirets et sans l'indicatif pays. On compare sur
        // les chiffres seuls et on tolère l'indicatif manquant (`LIKE
        // '%suffixe'`) — seuil de 6 chiffres pour éviter un faux positif sur
        // une saisie trop courte.
        $phoneDigits = preg_replace('/\D+/', '', $code);

        $card = LoyaltyCard::query()
            ->with('client')
            ->where('restaurant_id', $restaurant->id)
            ->where(function ($q) use ($code, $isUuid, $phoneDigits) {
                $q->where('card_code', $code);
                if ($isUuid) {
                    $q->orWhere('qr_token', $code)
                        ->orWhereHas('client', fn ($c) => $c->where('uuid', $code));
                }
                if (strlen($phoneDigits) >= 6) {
                    $q->orWhereHas('client', function ($c) use ($phoneDigits) {
                        $c->whereRaw(
                            "regexp_replace(phone, '\\D', '', 'g') LIKE ?",
                            ["%{$phoneDigits}"],
                        );
                    });
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
    private const DEFAULT_FCFA_PER_POINT = 100;

    /**
     * POST /api/merchant/clients/{loyaltyCard}/stamps
     *
     * Accorde un tampon (ou des points) et débloque la récompense dès que
     * l'objectif du programme est atteint. La transaction est journalisée
     * dans `loyalty_transactions` — c'est elle qui alimente les statistiques.
     *
     * En mode "Achat" (`spend`), le gain n'est plus un forfait de 1 : le
     * marchand saisit le montant réel (`amount_fcfa`), converti en points au
     * taux du programme (`config.fcfa_per_point`, 100 par défaut — même
     * valeur que `LoyaltyProgramController::store`, c'est le taux déjà
     * annoncé au marchand pendant l'onboarding).
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
     * DELETE /api/merchant/clients/{loyaltyCard}/stamps
     *
     * Retire le dernier tampon accordé sur cette carte (erreur de saisie,
     * scan en double non intercepté...). Restaure `stamps_current` à sa
     * valeur exacte d'avant ce gain (voir `meta.before` posé par
     * `grantStampOrPoints`) et annule les récompenses qu'il avait
     * débloquées — refusé si l'une d'elles a déjà été utilisée par le
     * client, pour ne jamais lui reprendre un avantage déjà consommé.
     *
     * Append-only : la ligne `stamp` d'origine n'est JAMAIS mutée (aucun
     * statut `canceled`) — le retrait est journalisé par une nouvelle ligne
     * `stamp_reversal` (valeur négative, `meta.reverses_transaction_id`,
     * opérateur identifié). L'historique marchand ET client montre donc à
     * la fois le gain et son retrait, ce qui est l'exigence anti-fraude ;
     * les lignes déjà inversées ne sont plus éligibles à un nouveau retrait.
     */
    public function removeStamp(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorizeCard($request, $loyaltyCard);

        // Même verrou que `addStamp` : les deux opérations mutent
        // `stamps_current` sur la même carte, elles ne doivent jamais
        // s'exécuter en même temps.
        $lock = Cache::lock("add-stamp:{$loyaltyCard->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Validation déjà en cours pour cette carte, réessayez dans un instant.',
            ], 409);
        }

        try {
            // Ids des tampons déjà inversés par une reversal valide — lus
            // depuis `meta` en PHP pour rester indépendants du driver SQL
            // (SQLite en test, Postgres en dev).
            $reversedIds = collect(DB::table('loyalty_transactions')
                ->where('loyalty_card_id', $loyaltyCard->id)
                ->where('type', 'stamp_reversal')
                ->pluck('meta'))
                ->map(fn ($meta) => json_decode((string) $meta, true)['reverses_transaction_id'] ?? null)
                ->filter()
                ->all();

            $lastStamp = DB::table('loyalty_transactions')
                ->where('loyalty_card_id', $loyaltyCard->id)
                ->where('type', 'stamp')
                ->where('status', 'valid')
                ->when($reversedIds !== [], fn ($q) => $q->whereNotIn('id', $reversedIds))
                ->orderByDesc('id')
                ->first();

            if (! $lastStamp) {
                return response()->json(['message' => 'Aucun tampon à retirer.'], 422);
            }

            // Pré-vérification rapide (hors transaction, sans verrou ligne) :
            // rejette tout de suite le cas courant. Ne suffit pas à elle
            // seule — voir le re-check verrouillé ci-dessous.
            $rewards = LoyaltyReward::where('loyalty_transaction_id', $lastStamp->id)->get();

            if ($rewards->contains(fn ($r) => $r->status === 'used')) {
                return response()->json([
                    'message' => 'Impossible de retirer ce tampon : la récompense qu\'il a débloquée a déjà été utilisée.',
                ], 422);
            }

            $meta = $lastStamp->meta ? json_decode($lastStamp->meta, true) : null;
            if (! is_array($meta) || ! array_key_exists('before', $meta)) {
                return response()->json([
                    'message' => 'Ce tampon a été accordé avant la mise à jour du système et ne peut pas être retiré automatiquement.',
                ], 422);
            }

            $staffUserId = CurrentActor::resolve($request)->staffUser?->id;

            // Nombre de cycles franchis par le gain qu'on retire (journalisé
            // dans meta.cycles par grantStampOrPoints ; 0 pour les tampons
            // antérieurs à cette colonne). Ces cycles doivent disparaître du
            // compteur comme la progression et les récompenses associées.
            $cyclesUndone = (int) ($meta['cycles'] ?? 0);

            DB::transaction(function () use ($loyaltyCard, $lastStamp, $meta, $staffUserId, $cyclesUndone) {
                // Re-lecture verrouillée (`lockForUpdate`) : `redeemReward`
                // verrouille la même ligne avant de passer une récompense à
                // `used` (voir plus bas dans ce fichier). Sans ce verrou, un
                // scan de récompense concurrent au retrait pourrait passer
                // les deux vérifications "available" en parallèle, puis ce
                // retrait écraserait le `used` tout juste posé par un
                // `canceled` — la récompense légitimement consommée par le
                // client disparaîtrait de son historique.
                $rewards = LoyaltyReward::where('loyalty_transaction_id', $lastStamp->id)
                    ->lockForUpdate()
                    ->get();

                abort_if(
                    $rewards->contains(fn ($r) => $r->status === 'used'),
                    422,
                    'Impossible de retirer ce tampon : la récompense qu\'il a débloquée a déjà été utilisée.',
                );

                $progress = $loyaltyCard->progress ?? [];

                // D'autres récompenses (d'un cycle antérieur) peuvent rester
                // disponibles indépendamment de celles qu'on annule ici.
                $stillAvailable = LoyaltyReward::where('loyalty_card_id', $loyaltyCard->id)
                    ->where('status', 'available')
                    ->whereNotIn('id', $rewards->pluck('id'))
                    ->exists();

                $loyaltyCard->update([
                    'progress' => array_merge($progress, ['stamps_current' => $meta['before']]),
                    'status' => $stillAvailable ? 'reward_available' : 'active',
                    'completed_at' => null,
                    'last_activity_at' => now(),
                    'cycles_completed' => max(0, (int) $loyaltyCard->cycles_completed - $cyclesUndone),
                ]);

                foreach ($rewards as $reward) {
                    // `loyalty_transaction_id` reste pointé sur la ligne
                    // stamp ORIGINALE : c'est bien « la transaction qui a
                    // débloqué la récompense » (sémantique de la colonne,
                    // cf. migration). Le fait qu'elle soit annulée se lit
                    // via `canceled_*` + la reversal liée dans l'historique.
                    $reward->update([
                        'status' => 'canceled',
                        'canceled_at' => now(),
                        'cancel_reason' => 'Tampon retiré par le marchand',
                        'canceled_by_staff_user_id' => $staffUserId,
                    ]);
                }

                // Append-only : nouvelle ligne inverse plutôt que mutation
                // de la ligne d'origine. Valeur négative = miroir exact du
                // gain ; `reverses_transaction_id` permet de retrouver la
                // paire gain/retrait dans l'audit.
                DB::table('loyalty_transactions')->insert([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'type' => 'stamp_reversal',
                    'value' => -abs((float) $lastStamp->value),
                    'validation_method' => 'merchant_app',
                    'status' => 'valid',
                    'staff_user_id' => $staffUserId,
                    'meta' => json_encode(['reverses_transaction_id' => $lastStamp->id]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Annule les signaux `cycle_completed` générés par le gain
                // retiré (les plus récents d'abord) — append-only : statut
                // `canceled`, jamais de suppression.
                if ($cyclesUndone > 0) {
                    $cycleIds = DB::table('loyalty_transactions')
                        ->where('loyalty_card_id', $loyaltyCard->id)
                        ->where('type', 'cycle_completed')
                        ->where('status', 'valid')
                        ->orderByDesc('id')
                        ->limit($cyclesUndone)
                        ->pluck('id');

                    if ($cycleIds->isNotEmpty()) {
                        DB::table('loyalty_transactions')
                            ->whereIn('id', $cycleIds)
                            ->update(['status' => 'canceled', 'updated_at' => now()]);
                    }
                }
            });

            $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);
            LoyaltyCardUpdated::dispatch($freshCard);

            foreach ($rewards as $reward) {
                LoyaltyRewardUpdated::dispatch($reward->fresh()->load('loyaltyCard.client'));
            }

            return response()->json([
                'message' => 'Tampon retiré.',
                'client' => $this->cardData($freshCard),
            ]);
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
        LoyaltyProgram $program,
    ): JsonResponse {
        $request->validate([
            'amount_fcfa' => ['required', 'numeric', 'min:1'],
        ], [
            'amount_fcfa.required' => 'Le montant de l\'achat est requis pour ce programme.',
        ]);

        $tierService = app(LoyaltyTierService::class);
        $tiers = $tierService->tiers($program);

        $amountFcfa = (float) $request->input('amount_fcfa');
        $percentage = (float) ($program->config['cashback_percentage'] ?? 0);
        $earnedFcfa = round($amountFcfa * $percentage / 100, 2);

        $staffUserId = CurrentActor::resolve($request)->staffUser?->id;
        $restaurantId = $restaurant->id;
        $createdRewardIds = [];

        DB::transaction(function () use (
            $loyaltyCard, $program, $tierService, $earnedFcfa, $amountFcfa, $tiers, $restaurantId, $staffUserId, &$createdRewardIds,
        ) {
            DB::table('loyalty_transactions')->insert([
                'loyalty_card_id' => $loyaltyCard->id,
                'type' => 'cashback_earn',
                'value' => $earnedFcfa,
                'montant_commande_fcfa' => $amountFcfa,
                'validation_method' => 'merchant_app',
                'status' => 'valid',
                'staff_user_id' => $staffUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Si cette opération est la toute première du filleul sur cette
            // carte et qu'un parrainage est en attente, la valide et
            // débloque la récompense du parrain — voir `ReferralService`.
            $this->referralService->validateFirstOperation($loyaltyCard);

            $loyaltyCard->update([
                'cashback_balance_fcfa' => $loyaltyCard->cashback_balance_fcfa + $earnedFcfa,
                'last_activity_at' => now(),
            ]);

            // Relation déjà résolue dans ce contrôleur (`$program`) : évite
            // que `lifetimeMetric()` recharge le programme depuis une
            // relation potentiellement pas encore en cache sur ce modèle.
            $loyaltyCard->setRelation('loyaltyProgram', $program);
            $metricAfter = $tierService->lifetimeMetric($loyaltyCard);

            foreach ($this->cashbackTiersToUnlock($loyaltyCard, $tiers, $metricAfter) as $tier) {
                $rewardDescription = trim((string) ($tier['reward_description'] ?? ''));
                if ($rewardDescription === '') {
                    // Palier sans récompense configurée : attribue
                    // seulement le niveau (déjà reflété par
                    // `LoyaltyCard::level`, calculé à la lecture) — rien à
                    // débloquer.
                    continue;
                }

                $reward = LoyaltyReward::create([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'restaurant_id' => $restaurantId,
                    'program_tier_id' => $tier['id'],
                    'title' => $rewardDescription,
                    'unlocked_at' => now(),
                    'expires_at' => $tier['validity_days'] ? now()->addDays((int) $tier['validity_days']) : null,
                ]);
                $createdRewardIds[] = $reward->id;
            }
        });

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);
        LoyaltyCardUpdated::dispatch($freshCard);

        foreach (LoyaltyReward::whereIn('id', $createdRewardIds)->get() as $reward) {
            $reward->setRelation('loyaltyCard', $freshCard);
            LoyaltyRewardUpdated::dispatch($reward);
        }

        return response()->json([
            'message' => number_format($earnedFcfa, 0, ',', ' ').' FCFA de cashback crédités.',
            'reward_unlocked' => count($createdRewardIds) > 0,
            'rewards_unlocked_count' => count($createdRewardIds),
            'cashback_earned' => $earnedFcfa,
            'client' => $this->cardData($freshCard),
        ]);
    }

    /**
     * Paliers franchis entre `$before` et `$after` (métrique croissante,
     * jamais reset) — Tampons/Achats uniquement (voir `grantStampOrPoints`) :
     * - 1 seul palier configuré : répété à chaque multiple entier franchi
     *   (ex. tous les 10 tampons).
     * - 2+ paliers : chacun ne peut être franchi qu'une fois dans la vie de
     *   la carte (seuils strictement croissants), plafonné au dernier.
     *
     * Le cashback utilise `cashbackTiersToUnlock()` à la place (métrique pas
     * forcément croissante en base `solde`, before/after n'y suffit pas).
     *
     * @param  array  $tiers  Depuis `LoyaltyTierService::tiers()`.
     * @return array Sous-ensemble de `$tiers` (avec doublons possibles si mono-palier).
     */
    private function crossedTiers(array $tiers, float $before, float $after): array
    {
        if (count($tiers) === 0) {
            return [];
        }

        if (count($tiers) === 1) {
            $goal = $tiers[0]['goal'];
            $crossedBefore = intdiv((int) $before, $goal);
            $crossedAfter = intdiv((int) $after, $goal);

            return array_fill(0, max(0, $crossedAfter - $crossedBefore), $tiers[0]);
        }

        return array_values(array_filter(
            $tiers,
            fn ($tier) => $tier['goal'] > $before && $tier['goal'] <= $after,
        ));
    }

    /**
     * Paliers cashback à débloquer côté marchand pour ce crédit — jamais
     * re-débloqués une fois servis, même si la métrique redescend puis
     * remonte au-dessus d'un seuil déjà franchi (base `solde`, voir
     * `LoyaltyTierService::lifetimeMetric`). Contrairement à `crossedTiers`,
     * ne s'appuie pas sur un intervalle before/after (la métrique cashback
     * n'est pas forcément croissante) : la seule source de vérité est « ce
     * palier a-t-il déjà produit une récompense pour cette carte ? ». Un
     * palier sans récompense configurée n'a jamais de `LoyaltyReward` à
     * vérifier — le re-détecter à chaque appel ne crée rien, donc sans
     * risque de doublon (voir l'appelant, qui saute la création si le texte
     * de récompense est vide). S'applique aussi bien à 1 palier configuré
     * qu'à plusieurs : le cashback n'a pas de comportement "cycle répété".
     *
     * @param  array  $tiers  Depuis `LoyaltyTierService::tiers()`.
     */
    private function cashbackTiersToUnlock(LoyaltyCard $loyaltyCard, array $tiers, float $metricAfter): array
    {
        if ($tiers === []) {
            return [];
        }

        $alreadyUnlockedTierIds = LoyaltyReward::where('loyalty_card_id', $loyaltyCard->id)
            ->whereNotNull('program_tier_id')
            ->pluck('program_tier_id')
            ->all();

        return array_values(array_filter(
            $tiers,
            fn ($tier) => $tier['goal'] <= $metricAfter && ! in_array($tier['id'], $alreadyUnlockedTierIds, true),
        ));
    }

    /**
     * POST /api/merchant/clients/{loyaltyCard}/redeem-cashback
     *
     * Utilisation du solde cashback comme réduction sur un achat en cours —
     * plafonnée au solde et, si configuré, impossible tant que le solde n'a
     * pas atteint le seuil minimum (`cashback_redeem_threshold_fcfa`).
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
            'amount_fcfa' => ['required', 'numeric', 'min:1'],
            'redeem_amount_fcfa' => ['required', 'numeric', 'min:1'],
        ], [
            'amount_fcfa.required' => 'Le montant de l\'achat est requis.',
            'redeem_amount_fcfa.required' => 'Le montant de cashback à utiliser est requis.',
        ]);

        $amountFcfa = (float) $request->input('amount_fcfa');
        $redeemAmount = (float) $request->input('redeem_amount_fcfa');

        // Indépendant du seuil configurable (`cashback_redeem_threshold_fcfa`,
        // optionnel) : cette règle s'applique toujours, même sans seuil
        // défini — le cashback utilisé ne réduit jamais l'achat sous zéro.
        if ($redeemAmount > $amountFcfa) {
            return response()->json([
                'message' => 'Le cashback utilisé ne peut pas dépasser le montant de l\'achat.',
            ], 422);
        }

        $threshold = $program->config['cashback_redeem_threshold_fcfa'] ?? null;
        if ($threshold !== null && $loyaltyCard->cashback_available_fcfa < (float) $threshold) {
            return response()->json([
                'message' => "Seuil non atteint : le solde doit atteindre {$threshold} FCFA avant utilisation (solde actuel : {$loyaltyCard->cashback_available_fcfa} FCFA).",
            ], 422);
        }

        $staffUserId = CurrentActor::resolve($request)->staffUser?->id;

        // Le verrou est acquis AVANT toute lecture du solde : vérifier le
        // solde avant la zone protégée laisserait deux requêtes simultanées
        // valider contre le même montant et débiter deux fois (TOCTOU).
        $lock = Cache::lock("redeem-cashback:{$loyaltyCard->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Validation déjà en cours pour cette carte, réessayez dans un instant.',
            ], 409);
        }

        try {
            DB::transaction(function () use ($loyaltyCard, $redeemAmount, $amountFcfa, $threshold, $staffUserId) {
                // Rechargement de la carte sous `LockForUpdate` : c'est ce
                // solde frais — pas celui lu hors verrou — qui est comparé.
                $card = LoyaltyCard::query()
                    ->whereKey($loyaltyCard->id)
                    ->lockForUpdate()
                    ->first();

                if ($card === null || $redeemAmount > $card->cashback_available_fcfa) {
                    throw ValidationException::withMessages([
                        'redeem_amount_fcfa' => 'Solde cashback insuffisant.',
                    ]);
                }

                if ($threshold !== null && $card->cashback_available_fcfa < (float) $threshold) {
                    throw ValidationException::withMessages([
                        'redeem_amount_fcfa' => "Seuil non atteint : le solde doit atteindre {$threshold} FCFA avant utilisation.",
                    ]);
                }

                $card->update([
                    'cashback_balance_fcfa' => $loyaltyCard->cashback_balance_fcfa - $redeemAmount,
                    'last_activity_at' => now(),
                ]);

                DB::table('loyalty_transactions')->insert([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'type' => 'cashback_redeem',
                    'value' => $redeemAmount,
                    'montant_commande_fcfa' => $amountFcfa,
                    'validation_method' => 'merchant_app',
                    'status' => 'valid',
                    'staff_user_id' => $staffUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } finally {
            $lock->release();
        }

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);
        LoyaltyCardUpdated::dispatch($freshCard);

        return response()->json([
            'message' => 'Cashback utilisé.',
            'client' => $this->cardData($freshCard),
        ]);
    }

    /**
     * GET /api/merchant/clients/{loyaltyCard}/history
     *
     * Historique consultable par l'admin ET l'opérateur (contrairement à
     * `/merchant/clients` en liste, réservé admin) — voir spec équipe.
     */
    public function clientHistory(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorizeCard($request, $loyaltyCard);

        $entries = DB::table('loyalty_transactions')
            ->leftJoin('staff_users', 'staff_users.id', '=', 'loyalty_transactions.staff_user_id')
            ->where('loyalty_transactions.loyalty_card_id', $loyaltyCard->id)
            // `stamp_reversal` : le retrait d'un tampon est une opération
            // à part entière de l'historique anti-fraude — il doit être
            // visible, pas masqué par la mutation silencieuse du gain.
            ->whereIn('loyalty_transactions.type', ['stamp', 'stamp_reversal', 'cashback_earn', 'cashback_redeem'])
            ->where('loyalty_transactions.status', 'valid')
            ->orderByDesc('loyalty_transactions.created_at')
            ->orderByDesc('loyalty_transactions.id')
            ->limit(100)
            ->get([
                'loyalty_transactions.type',
                'loyalty_transactions.value',
                'loyalty_transactions.montant_commande_fcfa',
                'loyalty_transactions.created_at',
                'staff_users.name as staff_name',
                'staff_users.role as staff_role',
            ]);

        $numeric = function ($value) {
            if ($value === null) {
                return null;
            }
            $float = (float) $value;

            return floor($float) == $float ? (int) $float : $float;
        };

        $history = $entries->map(fn ($row) => [
            'type' => $row->type,
            'value' => $numeric($row->value),
            'montant_commande_fcfa' => $numeric($row->montant_commande_fcfa),
            'created_at' => $row->created_at,
            'staff_name' => $row->staff_name,
            'staff_role' => $row->staff_role,
        ]);

        return response()->json(['history' => $history]);
    }

    private function grantStampOrPoints(
        Request $request,
        Restaurant $restaurant,
        LoyaltyCard $loyaltyCard,
        LoyaltyProgram $program,
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

        $tierService = app(LoyaltyTierService::class);
        $tiers = $tierService->tiers($program);

        if ($loyaltyCard->completed_at !== null) {
            return response()->json([
                'message' => 'Ce programme est terminé pour cette carte : aucune nouvelle progression n\'est possible.',
            ], 422);
        }

        $progress = $loyaltyCard->progress ?? [];
        $before = (int) ($progress['stamps_current'] ?? 0);
        $loops = (bool) $program->loops;

        // Objectif du dernier palier = largeur d'un cycle complet, valable
        // pour 1 ou N paliers (remplace l'ancien branchement sur le nombre
        // de paliers : c'est désormais `loops` qui pilote reset vs plafond).
        $cycleGoal = count($tiers) > 0 ? $tiers[count($tiers) - 1]['goal'] : null;

        $unlockedTiers = []; // [['reward_description' => string, 'validity_days' => ?int, 'id' => ?int, 'order' => int, 'level_name' => ?string], ...]
        $fullCyclesCompleted = 0;
        $cardCompleted = false;
        $current = $before + $earned;

        if ($cycleGoal !== null) {
            if ($loops) {
                // Boucle : consomme le cycle courant puis wrap à 0 — un
                // gros gain peut franchir plusieurs cycles d'un coup.
                $cursor = $before;
                $target = $before + $earned;
                while ($target >= $cycleGoal) {
                    $unlockedTiers = array_merge($unlockedTiers, $this->crossedTiers($tiers, $cursor, $cycleGoal));
                    $fullCyclesCompleted++;
                    $target -= $cycleGoal;
                    $cursor = 0;
                }
                $unlockedTiers = array_merge($unlockedTiers, $this->crossedTiers($tiers, $cursor, $target));
                $current = $target;
            } else {
                // Cycle unique : plafonné au dernier palier, la carte se
                // termine dès qu'il est atteint.
                $rawTarget = $before + $earned;
                $current = min($rawTarget, $cycleGoal);
                $unlockedTiers = $this->crossedTiers($tiers, $before, $current);
                if ($rawTarget >= $cycleGoal) {
                    $cardCompleted = true;
                    $fullCyclesCompleted = 1;
                }
            }
        }

        $cyclesCompleted = count($unlockedTiers);
        $rewardUnlocked = $cyclesCompleted > 0;

        // Niveau max historique — uniquement pour le multi-palier (le
        // mono-palier n'a pas de notion de "niveau", voir LoyaltyTierService)
        // — jamais rétrogradé, indépendant d'un futur reset de cycle.
        $maxLevelUpdate = [];
        if (count($tiers) > 1 && $unlockedTiers !== []) {
            $best = collect($unlockedTiers)->sortByDesc('order')->first();
            if ($best !== null && (int) $loyaltyCard->max_level_order < (int) $best['order']) {
                $maxLevelUpdate = [
                    'max_level_name' => $best['level_name'],
                    'max_level_order' => $best['order'],
                    'max_level_reached_at' => now(),
                ];
            }
        }

        $staffUserId = CurrentActor::resolve($request)->staffUser?->id;
        $restaurantId = $restaurant->id;
        $createdRewardIds = [];

        DB::transaction(function () use (
            $loyaltyCard, $progress, $before, $current, $rewardUnlocked, $unlockedTiers,
            $cardCompleted, $fullCyclesCompleted, $cycleGoal, $maxLevelUpdate,
            $earned, $amountFcfa, $restaurantId, $staffUserId, &$createdRewardIds,
        ) {
            $loyaltyCard->update(array_merge(
                [
                    'progress' => array_merge($progress, ['stamps_current' => $current]),
                    'status' => $rewardUnlocked ? 'reward_available' : 'active',
                    'last_activity_at' => now(),
                    // Compteur à vie de cycles terminés — sert au filtrage
                    // marchand ; décrémenté par removeStamp via meta.cycles.
                    'cycles_completed' => $loyaltyCard->cycles_completed + $fullCyclesCompleted,
                ],
                $cardCompleted ? ['completed_at' => now()] : [],
                $maxLevelUpdate,
            ));

            // `meta.before`/`meta.after` capture le `stamps_current` juste
            // avant/après ce gain — c'est ce qui permet à `removeStamp` de
            // restaurer l'état exact sans avoir à rejouer la logique de
            // cycle/boucle en sens inverse. `meta.cycles` journalise les
            // cycles franchis par CE gain : removeStamp annulera exactement
            // autant de lignes `cycle_completed` et décrémentera le compteur.
            $stampTransactionId = DB::table('loyalty_transactions')->insertGetId([
                'loyalty_card_id' => $loyaltyCard->id,
                'type' => 'stamp',
                'value' => $earned,
                'montant_commande_fcfa' => $amountFcfa,
                'validation_method' => 'merchant_app',
                'status' => 'valid',
                'staff_user_id' => $staffUserId,
                'meta' => json_encode(['before' => $before, 'after' => $current, 'cycles' => $fullCyclesCompleted]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Si cette opération est la toute première du filleul sur cette
            // carte et qu'un parrainage est en attente, la valide et
            // débloque la récompense du parrain — voir `ReferralService`.
            $this->referralService->validateFirstOperation($loyaltyCard);

            // Signal historique de fin de cycle — vaut aussi bien pour un
            // mono-palier (boucle) que pour un multi-palier (boucle ou
            // dernier cycle unique) désormais.
            for ($i = 0; $i < $fullCyclesCompleted; $i++) {
                DB::table('loyalty_transactions')->insert([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'type' => 'cycle_completed',
                    'value' => $cycleGoal,
                    'validation_method' => 'merchant_app',
                    'status' => 'valid',
                    'staff_user_id' => $staffUserId, // même traçabilité opérateur que les autres insertions
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($unlockedTiers as $tier) {
                $reward = LoyaltyReward::create([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'loyalty_transaction_id' => $stampTransactionId,
                    'restaurant_id' => $restaurantId,
                    'program_tier_id' => $tier['id'],
                    'title' => $tier['reward_description'],
                    'unlocked_at' => now(),
                    'expires_at' => $tier['validity_days'] ? now()->addDays((int) $tier['validity_days']) : null,
                ]);
                $createdRewardIds[] = $reward->id;
            }
        });

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);

        // Diffusion Reverb : le wallet client se met à jour en direct, sans
        // pull-to-refresh (voir routes/channels.php, canal `loyalty.{clientId}`
        // déjà autorisé).
        LoyaltyCardUpdated::dispatch($freshCard);

        // Une récompense fraîchement débloquée doit apparaître dans l'écran
        // "Mes récompenses" du client sans qu'il n'ait à tirer pour rafraîchir.
        foreach (LoyaltyReward::whereIn('id', $createdRewardIds)->get() as $reward) {
            $reward->setRelation('loyaltyCard', $freshCard);
            LoyaltyRewardUpdated::dispatch($reward);
        }

        $message = match (true) {
            $cardCompleted && $cyclesCompleted > 0 => 'Dernier palier atteint : programme terminé pour cette carte !',
            $cyclesCompleted > 1 => "{$cyclesCompleted} récompenses débloquées !",
            $cyclesCompleted === 1 => 'Récompense débloquée !',
            default => $isSpendMode ? "{$earned} point(s) accordé(s)." : 'Tampon accordé.',
        };

        return response()->json([
            'message' => $message,
            'reward_unlocked' => $rewardUnlocked,
            'rewards_unlocked_count' => $cyclesCompleted,
            'points_earned' => $earned,
            'program_completed' => $cardCompleted,
            'client' => $this->cardData($freshCard),
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

        $reward = LoyaltyReward::query()
            ->with('loyaltyCard.client')
            ->where('restaurant_id', $restaurant->id)
            ->where('redeem_token', trim($request->query('token')))
            ->first();

        if (! $reward) {
            return response()->json([
                'message' => 'Aucune récompense de votre commerce ne correspond à ce code.',
            ], 404);
        }

        return response()->json(['reward' => $this->rewardData($reward, withToken: true)]);
    }

    /**
     * POST /api/merchant/rewards/{loyaltyReward}/redeem
     */
    public function redeemReward(Request $request, LoyaltyReward $loyaltyReward): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        abort_if($loyaltyReward->restaurant_id !== $restaurant->id, 403, 'Cette récompense ne concerne pas votre commerce.');

        $request->validate(['token' => ['required', 'string']]);

        if (! hash_equals((string) $loyaltyReward->redeem_token, (string) $request->input('token'))) {
            return response()->json([
                'message' => 'Code de récompense invalide.',
            ], 422);
        }

        $lock = Cache::lock("redeem-reward:{$loyaltyReward->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Validation déjà en cours pour cette récompense, réessayez dans un instant.',
            ], 409);
        }

        try {
            $usedByStaffUserId = CurrentActor::resolve($request)->staffUser?->id;

            // Re-lecture verrouillée dans une transaction : symétrique du
            // `lockForUpdate` de `removeStamp` sur la même ligne. Sans ça, un
            // retrait de tampon concurrent pourrait committer sa propre
            // annulation entre notre `isRedeemable()` (lu avant le verrou) et
            // ce `update()`, et cette validation écraserait le `canceled`
            // fraîchement posé pour remettre `used` — la récompense
            // paraîtrait consommée alors qu'elle vient d'être retirée.
            DB::transaction(function () use ($loyaltyReward, $usedByStaffUserId) {
                $reward = LoyaltyReward::whereKey($loyaltyReward->id)->lockForUpdate()->first();

                abort_if(! $reward->isRedeemable(), 422, $reward->status !== 'available'
                    ? 'Cette récompense a déjà été utilisée ou annulée.'
                    : 'Cette récompense a expiré.');

                $reward->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'used_by_staff_user_id' => $usedByStaffUserId,
                ]);
            });
        } finally {
            $lock->release();
        }

        $freshReward = $loyaltyReward->fresh()->load('loyaltyCard.client');
        LoyaltyRewardUpdated::dispatch($freshReward);

        return response()->json([
            'message' => 'Récompense validée.',
            'reward' => $this->rewardData($freshReward),
        ]);
    }

    /**
     * POST /api/merchant/rewards/{loyaltyReward}/cancel
     */
    public function cancelReward(Request $request, LoyaltyReward $loyaltyReward): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        abort_if($loyaltyReward->restaurant_id !== $restaurant->id, 403, 'Cette récompense ne concerne pas votre commerce.');

        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $lock = Cache::lock("cancel-reward:{$loyaltyReward->id}", 5);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Annulation déjà en cours pour cette récompense, réessayez dans un instant.',
            ], 409);
        }

        try {
            // Relecture sous verrou : une validation concurrente peut avoir
            // changé le statut entre le binding de route et l'acquisition.
            if ($loyaltyReward->refresh()->status !== 'available') {
                return response()->json([
                    'message' => 'Seule une récompense encore disponible peut être annulée.',
                ], 422);
            }

            $loyaltyReward->update([
                'status' => 'canceled',
                'canceled_at' => now(),
                'cancel_reason' => $request->input('reason'),
                'canceled_by_staff_user_id' => CurrentActor::resolve($request)->staffUser?->id,
            ]);
        } finally {
            $lock->release();
        }

        $freshReward = $loyaltyReward->fresh()->load('loyaltyCard.client');
        LoyaltyRewardUpdated::dispatch($freshReward);

        return response()->json([
            'message' => 'Récompense annulée.',
            'reward' => $this->rewardData($freshReward),
        ]);
    }

    /**
     * GET /api/merchant/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant($request);

        $cardIds = LoyaltyCard::where('restaurant_id', $restaurant->id)->pluck('id');

        // Seuls les vrais gains comptent : type `stamp` (tampons ET points,
        // même type interne) — les lignes `cycle_completed` et `cashback_*`
        // ne sont pas des tampons. Une reversal du jour compense son gain
        // (net = ce que le marchand a réellement accordé aujourd'hui).
        $stampsToday = (int) (DB::table('loyalty_transactions')
            ->whereIn('loyalty_card_id', $cardIds)
            ->whereIn('type', ['stamp', 'stamp_reversal'])
            ->where('status', 'valid')
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'stamp' THEN 1 ELSE -1 END), 0) as total")
            ->value('total'));

        $recent = LoyaltyCard::query()
            ->with('client')
            ->whereIn('id', $cardIds)
            ->whereNotNull('last_activity_at')
            ->orderByDesc('last_activity_at')
            ->limit(10)
            ->get()
            ->map(fn (LoyaltyCard $card) => [
                'client_name' => $this->clientName($card),
                'action' => $this->activityLabel($card),
                'at' => $card->last_activity_at?->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'total_clients' => $cardIds->count(),
            'stamps_today' => $stampsToday,
            'active_rewards' => LoyaltyCard::whereIn('id', $cardIds)
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

    /** Libellé de la dernière activité réelle de la carte (pas un hardcode). */
    private function activityLabel(LoyaltyCard $card): string
    {
        $lastType = DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->orderByDesc('id')
            ->value('type');

        return match ($lastType) {
            'stamp_reversal' => 'Tampon retiré',
            'cashback_earn' => 'Cashback crédité',
            'cashback_redeem' => 'Cashback utilisé',
            'cycle_completed' => 'Cycle terminé',
            // `stamp` couvre tampons ET points (même type interne) — le
            // libellé dépend du type de programme.
            default => $card->loyaltyProgram?->type === 'spend'
                ? 'Points accordés'
                : 'Tampon accordé',
        };
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
            'id' => (string) $card->id,
            'client_id' => (string) $card->client_id,
            'restaurant_id' => (string) $card->restaurant_id,
            'card_code' => $card->card_code,
            'stamps_current' => (int) ($card->progress['stamps_current'] ?? 0),
            'cashback_balance_fcfa' => $card->cashback_available_fcfa,
            'status' => $card->status,
            'level' => $card->level,
            'cycles_completed' => (int) $card->cycles_completed,
            'reward_available' => $card->status === 'reward_available',
            'program_completed' => $card->completed_at !== null,
            'max_level' => $card->max_level_name ? [
                'name' => $card->max_level_name,
                'reached_at' => $card->max_level_reached_at?->toIso8601String(),
            ] : null,
            'last_activity_at' => $card->last_activity_at?->toIso8601String(),
            'created_at' => $card->created_at?->toIso8601String(),
            'client' => $card->client ? [
                'id' => (string) $card->client->id,
                'uuid' => $card->client->uuid,
                'name' => $this->clientName($card),
                'first_name' => $card->client->first_name,
                'last_name' => $card->client->last_name,
                'phone' => $card->client->phone,
                'email' => $card->client->email,
                'city' => $card->client->city,
                'country' => $card->client->country,
                'birthdate' => $card->client->birthdate?->toDateString(),
                'avatar_url' => $card->client->avatar_url,
            ] : null,
        ];
    }

    private function rewardData(LoyaltyReward $reward, bool $withToken = false): array
    {
        $card = $reward->loyaltyCard;

        $data = [
            'id' => (string) $reward->id,
            'title' => $reward->title,
            'status' => $reward->status,
            'is_expired' => $reward->is_expired,
            'unlocked_at' => $reward->unlocked_at?->toIso8601String(),
            'expires_at' => $reward->expires_at?->toIso8601String(),
            'used_at' => $reward->used_at?->toIso8601String(),
            'client' => $card?->client ? [
                'name' => $this->clientName($card),
                'phone' => $card->client->phone,
            ] : null,
        ];

        // Jeton QR exposé uniquement au lookup (le scan en est la source) —
        // jamais dans les réponses de liste ou de mutation.
        if ($withToken) {
            $data['token'] = $reward->redeem_token;
        }

        return $data;
    }
}
