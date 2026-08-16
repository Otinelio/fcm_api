<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\Api\RewardAckController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\RestaurantAuthController;
use App\Http\Controllers\Api\LoyaltyProgramController;
use App\Http\Controllers\Api\LoyaltyCardController;

// ─────────────────────────────────────────────────────────────────────────────
// Auth routes (clients mobiles)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    // Routes publiques (pas de token nécessaire)
    Route::post('/validate-register-step1', [ClientAuthController::class, 'validateRegisterStep1'])->middleware('throttle:5,1');
    Route::post('/register', [ClientAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login',    [ClientAuthController::class, 'login']);
    Route::post('/social',   [ClientAuthController::class, 'socialLogin']);

    // Password Recovery
    Route::post('/forgot-password', [ClientAuthController::class, 'forgotPassword']);
    Route::post('/verify-otp',      [ClientAuthController::class, 'verifyResetOtp']);
    Route::post('/reset-password',  [ClientAuthController::class, 'resetPassword']);

    // Routes protégées (token Sanctum requis)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',                       [ClientAuthController::class, 'me']);
        Route::put('/profile',                  [ClientAuthController::class, 'updateProfile']);
        Route::post('/profile/avatar',          [ClientAuthController::class, 'uploadAvatar'])->middleware('throttle:10,1');
        Route::delete('/profile/avatar',        [ClientAuthController::class, 'deleteAvatar']);
        Route::post('/social/complete-profile', [ClientAuthController::class, 'completeSocialProfile']);
        Route::post('/verify-password',         [ClientAuthController::class, 'verifyPassword']);
        Route::put('/change-password',          [ClientAuthController::class, 'changePassword']);
        Route::post('/logout',                  [ClientAuthController::class, 'logout']);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Auth routes (marchands / établissements) — scope : inscription + connexion
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth/merchant')->group(function () {
    // Routes publiques
    Route::post('/register', [RestaurantAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login',    [RestaurantAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/social',   [RestaurantAuthController::class, 'socialLogin']);

    // Password Recovery
    Route::post('/forgot-password', [RestaurantAuthController::class, 'forgotPassword']);
    Route::post('/verify-otp',      [RestaurantAuthController::class, 'verifyResetOtp']);
    Route::post('/reset-password',  [RestaurantAuthController::class, 'resetPassword']);

    // Routes protégées (token Sanctum requis)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',        [RestaurantAuthController::class, 'me']);
        Route::put('/profile',   [RestaurantAuthController::class, 'updateBusinessInfo']);
        Route::post('/logout',   [RestaurantAuthController::class, 'logout']);
    });
});

// Programme de fidélité (step2/3 de l'onboarding marchand)
Route::middleware('auth:sanctum')->post('/loyalty-programs', [LoyaltyProgramController::class, 'store']);

Route::middleware('auth:sanctum')->prefix('loyalty-cards')->group(function () {
    Route::post('/join', [LoyaltyCardController::class, 'join']);
    Route::get('/{loyaltyCard}', [LoyaltyCardController::class, 'show']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Autres routes existantes
// ─────────────────────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->post(
    '/rewards/{reward}/ack',
    RewardAckController::class
);

Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});

use App\Http\Controllers\FedaPayWebhookController;
Route::post('/webhooks/fedapay', [FedaPayWebhookController::class, 'handle']);

// Legacy login route (users table) — garder pour rétrocompatibilité
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

use App\Http\Controllers\PaymentController;

// Profil utilisateur : nom + solde de points de fidélité
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/subscriptions/{plan}/pay', [PaymentController::class, 'initSubscriptionPayment']);
    Route::get('/profile', function (Request $request) {
        $user = $request->user();
        return response()->json([
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'loyalty_points' => $user->loyalty_points ?? 0,
        ]);
    });

    Route::get('/customers/{customer}', function (\App\Models\User $customer) {
        return response()->json([
            'customer_id' => $customer->id,
            'loyalty_points' => $customer->loyalty_points,
        ]);
    });
});

// Route d'administration pour ajouter un point (non protégée pour les besoins du test)
Route::post('/customers/{customer}/add-point', [LoyaltyController::class, 'addPoint']);

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
    } elseif ($request->type === 'login_confirmation') {
        foreach ($user->deviceTokens as $deviceToken) {
            \App\Jobs\SendPromoNotification::dispatch(
                $user->id,
                $deviceToken->token,
                ['title' => 'Connexion réussie ✅', 'body' => 'Heureux de vous revoir !']
            );
        }
    } elseif ($request->type === 'online_only') {
        $fcm = app(\App\Services\Fcm\FcmService::class);
        $fcm->sendToTopic(
            'all_users',
            [], // Empty notification array means it's a silent data message
            ['type' => 'online_only', 'message' => 'Alerte in-app : Message pour tous les connectés !']
        );
    } elseif ($request->type === 'all_users') {
        $fcm = app(\App\Services\Fcm\FcmService::class);
        $fcm->sendToTopic(
            'all_users',
            ['title' => 'Mise à jour pour tous 📢', 'body' => 'Découvrez nos nouveautés !'],
            ['type' => 'promo']
        );
    } elseif ($request->type === 'points_gt_10') {
        \App\Models\User::where('loyalty_points', '>', 10)
            ->whereHas('deviceTokens')
            ->chunk(200, function ($users) {
                foreach ($users as $u) {
                    foreach ($u->deviceTokens as $deviceToken) {
                        \App\Jobs\SendPromoNotification::dispatch(
                            $u->id,
                            $deviceToken->token,
                            ['title' => 'Client Fidèle 🌟', 'body' => "Vos {$u->loyalty_points} points vous donnent droit à un cadeau !"]
                        );
                    }
                }
            });
    }

    return response()->json(['success' => true]);
});
