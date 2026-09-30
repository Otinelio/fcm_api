<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceTokenController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'token' => 'required|string|max:500',
            'platform' => 'nullable|string|in:android,ios,web',
        ]);
        $actor = $request->user();

        // Important : un token ne peut appartenir qu'à un seul compte à la fois
        // (Client, Restaurant ou User). S'il existait déjà rattaché à un autre
        // compte (device revendu/partagé), on le détache.
        DeviceToken::where('token', $request->token)
            ->where(function ($query) use ($actor) {
                $query->where('tokenable_type', '!=', $actor::class)
                    ->orWhere('tokenable_id', '!=', $actor->getKey());
            })
            ->delete();

        $actor->deviceTokens()->updateOrCreate(
            ['token' => $validated['token']],
            ['platform' => $validated['platform'] ?? null, 'last_used_at' => now()]
        );

        return response()->noContent();
    }
}
