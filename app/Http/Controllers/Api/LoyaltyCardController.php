<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoyaltyCardController extends Controller
{
    public function __construct(private readonly ReferralService $referralService)
    {
    }

    /**
     * GET /api/loyalty-cards
     *
     * Toutes les cartes du client authentifié — c'est cet appel qui repeuple
     * le wallet au démarrage de l'app. Sans lui, les cartes créées par
     * `join()` n'existaient que le temps de la session en mémoire : chaque
     * relance de l'app repartait des données de démo.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        $cards = $client->loyaltyCards()
            ->with(['restaurant', 'loyaltyProgram'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['cards' => $cards]);
    }

    /**
     * POST /api/loyalty-cards/join
     *
     * Rejoint le programme de fidélité d'un restaurant à partir de son
     * `qr_token` (scan QR caméra) ou de son `short_code` (saisie manuelle
     * côté client — le `qr_token` est un UUID à 36 caractères, imprononçable
     * et illisible à taper à la main). Idempotent : un second scan renvoie
     * la même carte plutôt que d'en créer une autre.
     *
     * Reconnaît aussi un QR/code de parrainage (`referral_qr_token` ou
     * `referral_code` d'une carte existante, préfixe `MIVAFID-REFERRAL:`
     * optionnel) : dans ce cas l'établissement est résolu depuis la carte
     * du parrain, et un `Referral` `pending` est créé — voir
     * `ReferralService`. Aucune récompense n'est attribuée ici : elle ne
     * l'est qu'à la première opération de fidélité du filleul.
     */
    public function join(Request $request): JsonResponse
    {
        $request->validate([
            'qr_token' => ['required', 'string'],
        ]);

        /** @var Client $client */
        $client = $request->user();

        $rawCode = trim($request->qr_token);
        $lookupCode = str_starts_with($rawCode, ReferralService::QR_PREFIX)
            ? substr($rawCode, strlen(ReferralService::QR_PREFIX))
            : $rawCode;

        $referrerCard = LoyaltyCard::where('referral_qr_token', strtolower($lookupCode))
            ->orWhere('referral_code', strtoupper($lookupCode))
            ->first();

        if ($referrerCard) {
            return $this->joinViaReferral($client, $referrerCard);
        }

        $restaurant = Restaurant::where('qr_token', strtolower($rawCode))
            ->orWhere('short_code', strtoupper($rawCode))
            ->first();

        if (! $restaurant) {
            return response()->json([
                'message' => 'Ce code n\'est associé à aucun commerce.',
            ], 404);
        }

        $program = $restaurant->loyaltyProgram;

        if (! $program) {
            return response()->json([
                'message' => 'Ce commerce n\'a pas encore activé de programme de fidélité.',
            ], 404);
        }

        $card = LoyaltyCard::firstOrCreate(
            ['client_id' => $client->id, 'restaurant_id' => $restaurant->id],
            ['loyalty_program_id' => $program->id],
        );

        // `firstOrCreate` positionne cet attribut avant tout rechargement —
        // il faut le lire ici, `load()` ne le préserve pas forcément.
        $wasRecentlyCreated = $card->wasRecentlyCreated;

        $card->load(['restaurant', 'loyaltyProgram']);

        return response()->json([
            'message' => $wasRecentlyCreated
                ? 'Carte de fidélité rejointe.'
                : 'Vous êtes déjà membre de ce commerce.',
            'card' => $card,
            'was_recently_created' => $wasRecentlyCreated,
        ], 201);
    }

    private function joinViaReferral(Client $client, LoyaltyCard $referrerCard): JsonResponse
    {
        if ($referrerCard->client_id === $client->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas utiliser votre propre code de parrainage.',
            ], 422);
        }

        $alreadyMember = LoyaltyCard::where('client_id', $client->id)
            ->where('restaurant_id', $referrerCard->restaurant_id)
            ->exists();

        if ($alreadyMember) {
            return response()->json([
                'message' => 'Vous êtes déjà membre de ce commerce, le parrainage ne peut plus s\'appliquer.',
            ], 422);
        }

        $restaurant = $referrerCard->restaurant;
        $program = $restaurant->loyaltyProgram;

        if (! $program) {
            return response()->json([
                'message' => 'Ce commerce n\'a pas encore activé de programme de fidélité.',
            ], 404);
        }

        $card = DB::transaction(function () use ($client, $restaurant, $program, $referrerCard) {
            $card = LoyaltyCard::create([
                'client_id' => $client->id,
                'restaurant_id' => $restaurant->id,
                'loyalty_program_id' => $program->id,
            ]);

            $this->referralService->attach($referrerCard, $card);

            return $card;
        });

        $card->load(['restaurant', 'loyaltyProgram']);

        return response()->json([
            'message' => 'Carte de fidélité rejointe grâce à un parrainage.',
            'card' => $card,
            'was_recently_created' => true,
            'referred_by' => $referrerCard->client()->value('first_name'),
        ], 201);
    }

    /**
     * GET /api/loyalty-cards/{loyaltyCard}
     */
    public function show(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        if ($loyaltyCard->client_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Cette carte ne vous appartient pas.',
            ], 403);
        }

        $loyaltyCard->load(['restaurant', 'loyaltyProgram']);

        return response()->json(['card' => $loyaltyCard]);
    }

    /**
     * GET /api/loyalty-cards/{loyaltyCard}/history
     *
     * Historique réel des opérations de la carte (tampons/points accordés,
     * cashback crédité/utilisé) — remplace l'historique fabriqué côté
     * Flutter (`card_detail_screen.dart::_historyFor`, dates et montants
     * inventés). Ne renvoie pas les lignes `cycle_completed` : ce sont un
     * signal technique interne (comptage des cycles pour les niveaux), pas
     * une opération que le client a "faite" — la ligne `stamp`/`cashback_*`
     * correspondante suffit à raconter l'historique. Les `stamp_reversal`
     * (retraits de tampons par le marchand) sont en revanche affichées :
     * append-only, le client voit le gain ET son retrait.
     */
    public function history(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        if ($loyaltyCard->client_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Cette carte ne vous appartient pas.',
            ], 403);
        }

        $entries = DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $loyaltyCard->id)
            ->whereIn('type', ['stamp', 'stamp_reversal', 'cashback_earn', 'cashback_redeem'])
            ->where('status', 'valid')
            // `created_at` seul ne départage pas deux opérations survenues à
            // la même seconde (ex. crédit de points puis usage du cashback
            // dans la même requête de test) : `id` croissant reflète l'ordre
            // d'insertion réel, donc l'ordre chronologique exact.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['type', 'value', 'montant_commande_fcfa', 'created_at']);

        // Colonnes `decimal` : le driver Postgres les renvoie en chaînes
        // ("1.00"), pas en nombres — sans ce cast, le client recevrait des
        // valeurs à parser lui-même au lieu de nombres JSON. Entier quand la
        // partie décimale est nulle (tampons/points sont toujours entiers,
        // et la plupart des montants FCFA aussi) plutôt que systématiquement
        // flottant, pour ne pas afficher "1.0" là où "1" est attendu.
        $numeric = function ($value) {
            if ($value === null) {
                return null;
            }
            $float = (float) $value;

            return floor($float) == $float ? (int) $float : $float;
        };

        $entries = $entries->map(fn ($row) => [
            'type' => $row->type,
            'value' => $numeric($row->value),
            'montant_commande_fcfa' => $numeric($row->montant_commande_fcfa),
            'created_at' => $row->created_at,
        ]);

        return response()->json(['history' => $entries]);
    }
}
