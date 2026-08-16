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
     * POST /api/loyalty-cards/join
     *
     * Rejoint le programme de fidélité d'un restaurant à partir de son
     * `qr_token` (scan QR ou saisie manuelle côté client). Idempotent : un
     * second scan renvoie la même carte plutôt que d'en créer une autre.
     */
    public function join(Request $request): JsonResponse
    {
        $request->validate([
            'qr_token' => ['required', 'string'],
        ]);

        $restaurant = Restaurant::where('qr_token', $request->qr_token)->first();

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

        $card->load(['restaurant', 'loyaltyProgram']);

        return response()->json([
            'message' => 'Carte de fidélité rejointe.',
            'card'    => $card,
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
