<?php

use App\Http\Controllers\Api\ClientAdvertisementController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientProximityController;
use App\Http\Controllers\Api\LoyaltyCardController;
use App\Http\Controllers\Api\LoyaltyProgramController;
use App\Http\Controllers\Api\LoyaltyRewardController;
use App\Http\Controllers\Api\MerchantCampaignController;
use App\Http\Controllers\Api\MerchantDashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProximitySettingsController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RestaurantAuthController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RewardAckController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\FedaPayWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SocialRedirectController;
use App\Jobs\SendPromoNotification;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Fcm\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// Auth routes (clients mobiles)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    // Routes publiques (pas de token nécessaire)
    Route::post('/validate-register-step1', [ClientAuthController::class, 'validateRegisterStep1'])->middleware('throttle:5,1');
    Route::post('/register', [ClientAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [ClientAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/social', [ClientAuthController::class, 'socialLogin']);

    // Password Recovery
    Route::post('/forgot-password', [ClientAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/verify-otp', [ClientAuthController::class, 'verifyResetOtp'])->middleware('throttle:5,1');
    Route::post('/reset-password', [ClientAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    // Routes protégées (token Sanctum requis)
    Route::middleware(['auth:sanctum', 'client.only'])->group(function () {
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
    Route::post('/forgot-password', [RestaurantAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/verify-otp', [RestaurantAuthController::class, 'verifyResetOtp'])->middleware('throttle:5,1');
    Route::post('/reset-password', [RestaurantAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

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
    Route::delete('/clients/{loyaltyCard}/cashback', [MerchantDashboardController::class, 'removeCashback']);
    Route::post('/clients/{loyaltyCard}/redeem-cashback', [MerchantDashboardController::class, 'redeemCashback']);

    Route::get('/rewards/lookup', [MerchantDashboardController::class, 'lookupReward'])->middleware('throttle:merchant-lookup');
    Route::post('/rewards/{loyaltyReward}/redeem', [MerchantDashboardController::class, 'redeemReward']);
    Route::post('/rewards/{loyaltyReward}/cancel', [MerchantDashboardController::class, 'cancelReward']);

    Route::get('/campaigns', [MerchantCampaignController::class, 'index'])->middleware('admin.only');
    Route::post('/campaigns/draft', [MerchantCampaignController::class, 'saveDraft'])->middleware('admin.only');
    Route::get('/campaigns/recipients', [MerchantCampaignController::class, 'recipients'])->middleware('admin.only');
    Route::get('/campaigns/recipients-list', [MerchantCampaignController::class, 'recipientsList'])->middleware('admin.only');
    Route::get('/campaigns/{campaign}', [MerchantCampaignController::class, 'show'])->middleware('admin.only');
    Route::post('/campaigns', [MerchantCampaignController::class, 'store'])->middleware('admin.only');
    Route::post('/campaigns/{campaign}/archive', [MerchantCampaignController::class, 'archive'])->middleware('admin.only');
    Route::delete('/campaigns/{campaign}', [MerchantCampaignController::class, 'destroy'])->middleware('admin.only');
    Route::put('/campaigns/{campaign}', [MerchantCampaignController::class, 'update'])->middleware('admin.only');
    Route::post('/campaigns/{campaign}/resend', [MerchantCampaignController::class, 'resend'])->middleware('admin.only');

    Route::get('/referrals', [ReferralController::class, 'forRestaurant'])->middleware('admin.only');
    Route::get('/reviews', [ReviewController::class, 'index'])->middleware('admin.only');
    Route::get('/proximity-settings', [ProximitySettingsController::class, 'show'])->middleware('admin.only');
    Route::put('/proximity-settings', [ProximitySettingsController::class, 'update'])->middleware('admin.only');

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);
        Route::delete('/', [NotificationController::class, 'destroyAll']);
    });
});

Route::middleware(['auth:sanctum', 'client.only'])->prefix('loyalty-cards')->group(function () {
    Route::get('/', [LoyaltyCardController::class, 'index']);
    Route::post('/join', [LoyaltyCardController::class, 'join']);
    Route::get('/{loyaltyCard}', [LoyaltyCardController::class, 'show']);
    Route::get('/{loyaltyCard}/history', [LoyaltyCardController::class, 'history']);
});

Route::middleware(['auth:sanctum', 'client.only'])->get('/rewards', [LoyaltyRewardController::class, 'index']);

Route::middleware(['auth:sanctum', 'client.only'])->get('/referrals', [ReferralController::class, 'mine']);

Route::middleware(['auth:sanctum', 'client.only'])->post('/reviews', [ReviewController::class, 'store']);

Route::middleware(['auth:sanctum', 'client.only'])->post('/client/location/proximity-check', [ClientProximityController::class, 'check'])->middleware('throttle:60,1');

// Espace publicitaire client (public, throttle anti-scraping)
Route::get('/advertisements', [ClientAdvertisementController::class, 'index'])->middleware('throttle:60,1');
Route::get('/client/advertisements', [ClientAdvertisementController::class, 'index'])->middleware('throttle:60,1');

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

Route::post('/webhooks/fedapay', [FedaPayWebhookController::class, 'handle']);

// Paiement d'abonnement restaurant (administrateur uniquement)
Route::middleware(['auth:sanctum', 'admin.only'])->post('/subscriptions/{plan}/pay', [PaymentController::class, 'initSubscriptionPayment']);

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

Route::middleware(['auth:sanctum', 'client.only'])->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/{notification}', [NotificationController::class, 'destroy']);
    Route::delete('/', [NotificationController::class, 'destroyAll']);
});

// ⚠️  SÉCURITÉ : route /simulate désactivée.
// Permettait à tout utilisateur authentifié d'envoyer des notifications FCM
// à des topics globaux (all_users, vip_customers) et de déclencher des
// commandes Artisan. Dangereuse en production.
//
// Si cette route est nécessaire pour les tests internes, la protéger avec
// un guard super_admin ou un middleware dédié :
// Route::middleware(['auth:super_admins'])->post('/simulate', ...);

// Redirection directe vers les réseaux sociaux des établissements
Route::get('/r/{identifier}/{platform}', [SocialRedirectController::class, 'redirect']);

