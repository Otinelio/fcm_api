<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyCardController extends Controller
{
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
     */
    public function join(Request $request): JsonResponse
    {
        $request->validate([
            'qr_token' => ['required', 'string'],
        ]);

        $code = trim($request->qr_token);

        $restaurant = Restaurant::where('qr_token', strtolower($code))
            ->orWhere('short_code', strtoupper($code))
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

        /** @var Client $client */
        $client = $request->user();

        $card = LoyaltyCard::firstOrCreate(
            ['client_id' => $client->id, 'restaurant_id' => $restaurant->id],
            ['loyalty_program_id' => $program->id],
        );

        // `firstOrCreate` positionne cet attribut avant tout rechargement —
        // il faut le lire ici, `load()` ne le préserve pas forcément.
        $wasRecentlyCreated = $card->wasRecentlyCreated;

        $card->load(['restaurant', 'loyaltyProgram']);

        return response()->json([
            'message'              => $wasRecentlyCreated
                ? 'Carte de fidélité rejointe.'
                : 'Vous êtes déjà membre de ce commerce.',
            'card'                 => $card,
            'was_recently_created' => $wasRecentlyCreated,
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
}
