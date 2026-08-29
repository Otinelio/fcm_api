<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Referral;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * GET /api/referrals
     *
     * Parrainages faits par le client authentifié (pending + validated),
     * tous établissements confondus — alimente sa page Parrainage.
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        $referrals = Referral::where('referrer_client_id', $client->id)
            ->with(['restaurant', 'referredClient', 'reward'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['referrals' => $referrals]);
    }

    /**
     * GET /api/merchant/referrals
     *
     * Parrainages de l'établissement authentifié — parrain, filleul,
     * statut, récompense attribuée.
     */
    public function forRestaurant(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $referrals = Referral::where('restaurant_id', $restaurant->id)
            ->with(['referrerClient', 'referredClient', 'reward'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['referrals' => $referrals]);
    }
}
