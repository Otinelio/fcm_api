<?php

namespace App\Http\Controllers;

use App\Events\LoyaltyPointAdded;
use App\Models\Reward;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function addPoint(Request $request, User $customer)
    {
        $points = $request->integer('points', 1);

        $oldPoints = $customer->loyalty_points;
        $customer->increment('loyalty_points', $points);
        $newPoints = $customer->loyalty_points;

        LoyaltyPointAdded::dispatch($customer);

        // Si on franchit un palier de 500 points
        if (floor($oldPoints / 500) < floor($newPoints / 500)) {
            $reward = Reward::create([
                'customer_id' => $customer->id,
                'title' => 'Palier 500 pts atteint ! 🎉',
                'description' => 'Bravo, tu as débloqué une récompense exceptionnelle !',
                'unlocked_at' => now(),
            ]);

            // Module 7 : un seul appel, le dispatcher gère tout
            // (Reverb + presence check + fallback FCM adaptatif)
            app(NotificationDispatcher::class)->dispatchRewardUnlocked($reward);
        }

        return response()->json([
            'customer_id' => $customer->id,
            'loyalty_points' => $customer->loyalty_points,
        ]);
    }
}
