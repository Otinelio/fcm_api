<?php

use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\LoyaltyCardController;
use App\Http\Controllers\Api\LoyaltyProgramController;
use App\Http\Controllers\Api\LoyaltyRewardController;
use App\Http\Controllers\Api\MerchantCampaignController;
use App\Http\Controllers\Api\MerchantDashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RestaurantAuthController;
use App\Http\Controllers\Api\RewardAckController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\LoyaltyController;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// Auth routes (clients mobiles)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    // Routes publiques (pas de token nécessaire)
    Route::post('/validate-register-step1', [ClientAuthController::class, 'validateRegisterStep1'])->middleware('throttle:5,1');
    Route::post('/register', [ClientAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [ClientAuthController::class, 'login']);
    Route::post('/social', [ClientAuthController::class, 'socialLogin']);

    // Password Recovery
    Route::post('/forgot-password', [ClientAuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [ClientAuthController::class, 'verifyResetOtp']);
    Route::post('/reset-password', [ClientAuthController::class, 'resetPassword']);

    // Routes protégées (token Sanctum requis)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [ClientAuthController::class, 'me']);
        Route::put('/profile', [ClientAuthController::class, 'updateProfile']);
        Route::post('/profile/avatar', [ClientAuthController::class, 'uploadAvatar'])->middleware('throttle:10,1');
        Route::delete('/profile/avatar', [ClientAuthController::class, 'deleteAvatar']);
        Route::post('/social/complete-profile', [ClientAuthController::class, 'completeSocialProfile']);
        Route::post('/verify-password', [ClientAuthController::class, 'verifyPassword']);
        Route::put('/change-password', [ClientAuthController::class, 'changePassword']);
        Route::post('/logout', [ClientAuthController::class, 'logout']);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Auth routes (marchands / établissements) — scope : inscription + connexion
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth/merchant')->group(function () {
    // Routes publiques
    Route::post('/register', [RestaurantAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [RestaurantAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/social', [RestaurantAuthController::class, 'socialLogin']);
    Route::post('/staff/login', [StaffAuthController::class, 'login'])->middleware('throttle:5,1');

    // Password Recovery
    Route::post('/forgot-password', [RestaurantAuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [RestaurantAuthController::class, 'verifyResetOtp']);
    Route::post('/reset-password', [RestaurantAuthController::class, 'resetPassword']);

    // Routes protégées (token Sanctum requis)
    Route::middleware(['auth:sanctum', 'staff.active'])->group(function () {
        Route::get('/me', [RestaurantAuthController::class, 'me']);
        Route::put('/profile', [RestaurantAuthController::class, 'updateBusinessInfo'])->middleware('admin.only');
        Route::post('/profile/logo', [RestaurantAuthController::class, 'uploadLogo'])->middleware(['throttle:10,1', 'admin.only']);
        Route::delete('/profile/logo', [RestaurantAuthController::class, 'deleteLogo'])->middleware('admin.only');
        Route::put('/email', [RestaurantAuthController::class, 'updateEmail'])->middleware('admin.only');
        Route::delete('/account', [RestaurantAuthController::class, 'deleteAccount'])->middleware('admin.only');
        Route::put('/plan', [RestaurantAuthController::class, 'updatePlan'])->middleware('admin.only');
        Route::post('/verify-password', [RestaurantAuthController::class, 'verifyPassword']);
        Route::put('/change-password', [RestaurantAuthController::class, 'changePassword']);
        Route::put('/notification-preferences', [RestaurantAuthController::class, 'updateNotificationPreferences'])->middleware('admin.only');
        Route::get('/team', [TeamController::class, 'index'])->middleware('admin.only');
        Route::post('/team', [TeamController::class, 'store'])->middleware('admin.only');
        Route::put('/team/{staffUser}', [TeamController::class, 'update'])->middleware('admin.only');
        Route::patch('/team/{staffUser}/toggle-active', [TeamController::class, 'toggleActive'])->middleware('admin.only');
        Route::post('/logout', [RestaurantAuthController::class, 'logout']);
    });
});

// Programme de fidélité (step2/3 de l'onboarding marchand)
Route::middleware(['auth:sanctum', 'admin.only'])->post('/loyalty-programs', [LoyaltyProgramController::class, 'store']);

// Dashboard marchand (clientèle, validation, statistiques, campagnes)
Route::middleware(['auth:sanctum', 'staff.active'])->prefix('merchant')->group(function () {
    Route::get('/stats', [MerchantDashboardController::class, 'stats'])->middleware('admin.only');
    Route::get('/clients', [MerchantDashboardController::class, 'clients'])->middleware('admin.only');
    Route::get('/clients/lookup', [MerchantDashboardController::class, 'lookup'])->middleware('throttle:merchant-lookup');
    Route::get('/clients/{loyaltyCard}', [MerchantDashboardController::class, 'showClient']);
    Route::get('/clients/{loyaltyCard}/history', [MerchantDashboardController::class, 'clientHistory']);
    Route::post('/clients/{loyaltyCard}/stamps', [MerchantDashboardController::class, 'addStamp']);
    Route::delete('/clients/{loyaltyCard}/stamps', [MerchantDashboardController::class, 'removeStamp']);
    Route::post('/clients/{loyaltyCard}/redeem-cashback', [MerchantDashboardController::class, 'redeemCashback']);

    Route::get('/rewards/lookup', [MerchantDashboardController::class, 'lookupReward'])->middleware('throttle:merchant-lookup');
    Route::post('/rewards/{loyaltyReward}/redeem', [MerchantDashboardController::class, 'redeemReward']);
    Route::post('/rewards/{loyaltyReward}/cancel', [MerchantDashboardController::class, 'cancelReward']);

    Route::get('/campaigns', [MerchantCampaignController::class, 'index'])->middleware('admin.only');
    Route::get('/campaigns/recipients', [MerchantCampaignController::class, 'recipients'])->middleware('admin.only');
    Route::get('/campaigns/recipients-list', [MerchantCampaignController::class, 'recipientsList'])->middleware('admin.only');
    Route::post('/campaigns', [MerchantCampaignController::class, 'store'])->middleware('admin.only');

    Route::get('/referrals', [ReferralController::class, 'forRestaurant'])->middleware('admin.only');

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);
        Route::delete('/', [NotificationController::class, 'destroyAll']);
    });
});

Route::middleware('auth:sanctum')->prefix('loyalty-cards')->group(function () {
    Route::get('/', [LoyaltyCardController::class, 'index']);
    Route::post('/join', [LoyaltyCardController::class, 'join']);
    Route::get('/{loyaltyCard}', [LoyaltyCardController::class, 'show']);
    Route::get('/{loyaltyCard}/history', [LoyaltyCardController::class, 'history']);
});

Route::middleware('auth:sanctum')->get('/rewards', [LoyaltyRewardController::class, 'index']);

Route::middleware('auth:sanctum')->get('/referrals', [ReferralController::class, 'mine']);

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
Route::post('/login', [AuthController::class, 'login']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentController;
use App\Jobs\SendPromoNotification;
use App\Services\Fcm\FcmService;
use Illuminate\Support\Facades\Artisan;

// Profil utilisateur : nom + solde de points de fidélité
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/subscriptions/{plan}/pay', [PaymentController::class, 'initSubscriptionPayment'])->middleware('admin.only');
    Route::get('/profile', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'loyalty_points' => $user->loyalty_points ?? 0,
        ]);
    });

    Route::get('/customers/{customer}', function (User $customer) {
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
        ['token' => $request->token],
        ['platform' => $request->platform, 'last_used_at' => now()]
    );

    return response()->noContent();
});

Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/{notification}', [NotificationController::class, 'destroy']);
    Route::delete('/', [NotificationController::class, 'destroyAll']);
});

Route::middleware('auth:sanctum')->post('/simulate', function (Request $request) {
    $request->validate(['type' => 'required|string']);
    $user = $request->user();

    if ($request->type === 'promo') {
        foreach ($user->deviceTokens as $deviceToken) {
            SendPromoNotification::dispatch(
                $user->id,
                $deviceToken->token,
                ['title' => 'SUPER PROMO 💥', 'body' => 'Moins 50% sur votre commande !']
            );
        }
    } elseif ($request->type === 'birthday') {
        // Déclenche manuellement la logique anniversaire pour ce compte —
        // ne fonctionne que pour un Client authentifié (seul `birthdate`
        // existe sur ce modèle, pas sur `Restaurant`).
        if ($user instanceof \App\Models\Client) {
            $user->update(['birthdate' => now()->format('Y-m-d')]);
        }

        Artisan::call('notifications:birthdays');
    } elseif ($request->type === 'vip') {
        // Send a notification to the 'vip_customers' topic
        $fcm = app(FcmService::class);
        $fcm->sendToTopic(
            'vip_customers',
            ['title' => 'Accès VIP 👑', 'body' => 'Soirée privée ce vendredi dans notre restaurant !'],
            ['type' => 'promo']
        );
    } elseif ($request->type === 'login_confirmation') {
        foreach ($user->deviceTokens as $deviceToken) {
            SendPromoNotification::dispatch(
                $user->id,
                $deviceToken->token,
                ['title' => 'Connexion réussie ✅', 'body' => 'Heureux de vous revoir !']
            );
        }
    } elseif ($request->type === 'online_only') {
        $fcm = app(FcmService::class);
        $fcm->sendToTopic(
            'all_users',
            [], // Empty notification array means it's a silent data message
            ['type' => 'online_only', 'message' => 'Alerte in-app : Message pour tous les connectés !']
        );
    } elseif ($request->type === 'all_users') {
        $fcm = app(FcmService::class);
        $fcm->sendToTopic(
            'all_users',
            ['title' => 'Mise à jour pour tous 📢', 'body' => 'Découvrez nos nouveautés !'],
            ['type' => 'promo']
        );
    } elseif ($request->type === 'points_gt_10') {
        User::where('loyalty_points', '>', 10)
            ->whereHas('deviceTokens')
            ->chunk(200, function ($users) {
                foreach ($users as $u) {
                    foreach ($u->deviceTokens as $deviceToken) {
                        SendPromoNotification::dispatch(
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
