<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\DeviceToken;
use App\Models\NotificationLog;


Route::post('/login', function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Identifiants incorrects.'], 401);
    }

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'user' => $user
    ]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->post('/device-tokens', function (Request $request) {
    $request->validate(['token' => 'required|string']);

    // Important : un token ne peut appartenir qu'à un seul user à la fois.
    // S'il existait déjà rattaché à un autre user (device revendu/partagé), on le détache.
    DeviceToken::where('token', $request->token)
        ->where('user_id', '!=', $request->user()->id)
        ->delete();

    $request->user()->deviceTokens()->updateOrCreate(
        ['token' => $request->token],
        ['platform' => $request->platform, 'last_used_at' => now()]
    );

    return response()->noContent();
});

Route::middleware('auth:sanctum')->get('/notifications', function (Request $request) {
    return $request->user()->notificationLogs()->latest()->paginate(20);
});

Route::middleware('auth:sanctum')->post('/simulate', function (Request $request) {
    $request->validate(['type' => 'required|string']);
    $user = $request->user();

    if ($request->type === 'promo') {
        foreach ($user->deviceTokens as $deviceToken) {
            \App\Jobs\SendPromoNotification::dispatch(
                $user->id,
                $deviceToken->token,
                ['title' => 'SUPER PROMO 💥', 'body' => 'Moins 50% sur votre commande !']
            );
        }
    } elseif ($request->type === 'reward') {
        // Find or create a mock loyalty card for the user
        $card = \App\Models\LoyaltyCard::firstOrCreate(
            ['user_id' => $user->id],
            ['stamps' => 4, 'required_stamps' => 5]
        );
        
        // Simulate adding the 5th stamp
        $card->increment('stamps');
        event(new \App\Events\StampAdded($card));
    } elseif ($request->type === 'birthday') {
        // Manually trigger the birthday notification logic for this user
        // We set their birthday to today just for the simulation
        $user->update(['birthday' => now()->format('Y-m-d')]);
        
        // Call the command manually
        \Illuminate\Support\Facades\Artisan::call('notifications:birthdays');
    } elseif ($request->type === 'vip') {
        // Send a notification to the 'vip_customers' topic
        $fcm = app(\App\Services\Fcm\FcmService::class);
        $fcm->sendToTopic(
            'vip_customers',
            ['title' => 'Accès VIP 👑', 'body' => 'Soirée privée ce vendredi dans notre restaurant !'],
            ['type' => 'promo']
        );
    }

    return response()->json(['success' => true]);
});