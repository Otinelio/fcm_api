<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyReward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyRewardController extends Controller
{
    /**
     * GET /api/rewards
     *
     * Toutes les récompenses du client authentifié, tous établissements
     * confondus — c'est ce qui alimente l'écran "Mes récompenses".
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        $rewards = LoyaltyReward::query()
            ->whereHas('loyaltyCard', fn ($q) => $q->where('client_id', $client->id))
            ->with('restaurant')
            ->orderByDesc('unlocked_at')
            ->get();

        return response()->json(['rewards' => $rewards]);
    }
}
