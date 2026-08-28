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
            ->get()
            ->map(function (LoyaltyReward $reward) {
                $data = $reward->toArray();

                // Récompense "surprise" (voir birthday_reward.surprise) :
                // titre réel masqué au client tant qu'elle n'a pas été
                // utilisée — visible une fois redeemReward passé, pour que
                // l'historique du client reste exact. Le marchand, lui, voit
                // toujours le vrai titre (MerchantDashboardController::rewardData).
                if ($reward->is_surprise && $reward->status === 'available') {
                    $data['title'] = '🎁 Récompense surprise';
                }

                return $data;
            });

        return response()->json(['rewards' => $rewards]);
    }
}
