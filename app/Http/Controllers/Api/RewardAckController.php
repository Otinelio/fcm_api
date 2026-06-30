<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RewardAckController extends Controller
{
    public function __invoke(Request $request, Reward $reward)
    {
        // Sécurité : seul le propriétaire du reward peut l'acquitter
        abort_if($reward->customer_id !== $request->user()->id, 403);

        // Durée de vie courte : on n'a besoin de cette info que pendant
        // la fenêtre de décision du job de fallback (quelques secondes)
        Cache::put("reward_acked:{$reward->id}", true, now()->addMinutes(2));

        return response()->noContent();
    }
}
