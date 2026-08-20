<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRestaurantRequest;
use App\Http\Requests\Auth\LoginRestaurantRequest;
use App\Http\Requests\Auth\RegisterRestaurantRequest;
use App\Http\Requests\Auth\ResetPasswordRestaurantRequest;
use App\Http\Requests\Auth\SocialLoginRequest;
use App\Http\Requests\Auth\UpdateBusinessInfoRequest;
use App\Http\Requests\Auth\UpdateLogoRequest;
use App\Http\Requests\Auth\VerifyResetOtpRestaurantRequest;
use App\Models\Restaurant;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MatanYadaev\EloquentSpatial\Objects\Point;

class RestaurantAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuth,
    ) {}

    // ─────────────────────────────────────────────────────────
    // Register (email + password) — étape "Inscription"
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/merchant/register
     */
    public function register(RegisterRestaurantRequest $request): JsonResponse
    {
        $restaurant = Restaurant::create([
            'email'    => $request->email,
            'password' => $request->password, // Cast 'hashed' dans le modèle
        ]);

        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return response()->json([
            'message'          => 'Inscription réussie.',
            'access_token'     => $token,
            'token_type'       => 'Bearer',
            'restaurant'       => $this->restaurantData($restaurant),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────
    // Login (email + password)
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/merchant/login
     */
    public function login(LoginRestaurantRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $restaurant = Restaurant::where('email', $request->email)->first();

        if (! $restaurant) {
            $request->hitRateLimiter();

            return response()->json([
                'message' => 'Email ou mot de passe incorrect.',
            ], 401);
        }

        if ($restaurant->isOAuthUser()) {
            $request->hitRateLimiter();

            return response()->json([
                'message' => $restaurant->authMethodDeniedMessage(),
            ], 403);
        }

        if (! Hash::check($request->password, $restaurant->password)) {
            $request->hitRateLimiter();

            return response()->json([
                'message' => 'Email ou mot de passe incorrect.',
            ], 401);
        }

        $request->clearRateLimiter();

        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return response()->json([
            'message'      => 'Connexion réussie.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'restaurant'   => $this->restaurantData($restaurant),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Social Login (Google / Apple)
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/merchant/social
     *
     * Reçoit un id_token du SDK Google/Apple côté Flutter, le valide côté
     * serveur, et retourne un token + le restaurant (nouveau ou existant).
     * `has_business_info` indique au front s'il faut router vers step1.
     */
    public function socialLogin(SocialLoginRequest $request): JsonResponse
    {
        try {
            $oauthData = $this->socialAuth->validateToken(
                $request->provider,
                $request->id_token,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        $action = $request->input('action', 'login');
        $allowCreation = ($action === 'signup');

        try {
            ['restaurant' => $restaurant, 'is_new' => $isNew] = $this->socialAuth->findOrCreateRestaurant(
                provider: $request->provider,
                oauthId: $oauthData['sub'],
                email: $oauthData['email'] ?? null,
                allowCreation: $allowCreation,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return response()->json([
            'message'      => $isNew ? 'Compte créé via ' . $request->provider . '.' : 'Connexion réussie.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'restaurant'   => $this->restaurantData($restaurant),
        ], $isNew ? 201 : 200);
    }

    // ─────────────────────────────────────────────────────────
    // Business info (step1) — authentifié
    // ─────────────────────────────────────────────────────────

    /**
     * PUT /api/auth/merchant/profile
     */
    public function updateBusinessInfo(UpdateBusinessInfoRequest $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $data = collect($request->validated())->except(['latitude', 'longitude'])->all();
        if ($request->filled('latitude') && $request->filled('longitude')) {
            $data['location'] = new Point((float) $request->latitude, (float) $request->longitude);
        }

        $restaurant->update($data);

        return response()->json([
            'message'    => 'Informations du commerce mises à jour.',
            'restaurant' => $this->restaurantData($restaurant->fresh()),
        ]);
    }

    /**
     * POST /api/auth/merchant/profile/logo
     *
     * Upload (ou remplace) le logo du commerce.
     */
    public function uploadLogo(UpdateLogoRequest $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        // Un ré-upload peut changer de format (png -> jpg) : on nettoie toute
        // ancienne extension avant d'écrire la nouvelle, sinon l'ancien fichier
        // reste orphelin sur le disque.
        $this->purgeLogoFiles($restaurant);

        $extension = $request->file('logo')->extension();
        $path = $request->file('logo')->storeAs('logos', "{$restaurant->uuid}.{$extension}", 'public');

        if ($path === false) {
            return response()->json([
                'message' => 'Échec de l\'enregistrement du logo. Réessayez.',
            ], 500);
        }

        // Chemin déterministe (logos/{uuid}.{ext}) : un ré-upload avec la même
        // extension produirait la même URL, servie en cache côté client sinon.
        $restaurant->update(['logo_url' => asset(Storage::url($path)) . '?v=' . now()->timestamp]);

        return response()->json([
            'message'    => 'Logo mis à jour.',
            'restaurant' => $this->restaurantData($restaurant->fresh()),
        ]);
    }

    /**
     * DELETE /api/auth/merchant/profile/logo
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $this->purgeLogoFiles($restaurant);
        $restaurant->update(['logo_url' => null]);

        return response()->json([
            'message'    => 'Logo supprimé.',
            'restaurant' => $this->restaurantData($restaurant->fresh()),
        ]);
    }

    /**
     * Supprime tous les fichiers logo connus pour ce restaurant, toutes
     * extensions confondues (utilisé avant un ré-upload et lors de la
     * suppression).
     */
    private function purgeLogoFiles(Restaurant $restaurant): void
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            Storage::disk('public')->delete("logos/{$restaurant->uuid}.{$ext}");
        }
    }

    /**
     * PUT /api/auth/merchant/plan
     *
     * Rattache le commerce à une formule de la table `plans`. Le paiement
     * n'est pas encore branché : le changement est direct.
     */
    public function updatePlan(Request $request): JsonResponse
    {
        $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
        ]);

        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $restaurant->update([
            'plan_id' => DB::table('plans')->where('slug', $request->plan)->value('id'),
        ]);

        return response()->json([
            'message'    => 'Formule mise à jour.',
            'restaurant' => $this->restaurantData($restaurant->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Me / Logout
    // ─────────────────────────────────────────────────────────

    /**
     * GET /api/auth/merchant/me
     */
    public function me(): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = auth()->user();

        return response()->json([
            'restaurant' => $this->restaurantData($restaurant),
        ]);
    }

    /**
     * POST /api/auth/merchant/logout
     */
    public function logout(): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = auth()->user();

        $restaurant->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Password Recovery (OTP, même mécanisme que ClientAuthController)
    // ─────────────────────────────────────────────────────────

    public function forgotPassword(ForgotPasswordRestaurantRequest $request): JsonResponse
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put('otp_reset_merchant_' . $request->email, $otp, now()->addMinutes(10));

        Log::info("Code OTP de réinitialisation marchand pour {$request->email} : {$otp}");

        $response = ['message' => 'Un code de réinitialisation a été envoyé.'];

        if (config('app.debug')) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response);
    }

    public function verifyResetOtp(VerifyResetOtpRestaurantRequest $request): JsonResponse
    {
        $cachedOtp = Cache::get('otp_reset_merchant_' . $request->email);

        if (! $cachedOtp || $cachedOtp !== $request->otp) {
            return response()->json([
                'message' => 'Le code de réinitialisation est invalide ou a expiré.',
            ], 400);
        }

        $resetToken = (string) Str::uuid();
        Cache::put('reset_token_merchant_' . $request->email, $resetToken, now()->addMinutes(15));
        Cache::forget('otp_reset_merchant_' . $request->email);

        return response()->json([
            'message'     => 'Code vérifié avec succès.',
            'reset_token' => $resetToken,
        ]);
    }

    public function resetPassword(ResetPasswordRestaurantRequest $request): JsonResponse
    {
        $cachedToken = Cache::get('reset_token_merchant_' . $request->email);

        if (! $cachedToken || $cachedToken !== $request->reset_token) {
            return response()->json([
                'message' => 'La session de réinitialisation a expiré. Veuillez recommencer.',
            ], 400);
        }

        $restaurant = Restaurant::where('email', $request->email)->firstOrFail();

        $restaurant->update([
            'password' => Hash::make($request->password),
        ]);

        Cache::forget('reset_token_merchant_' . $request->email);
        $restaurant->tokens()->delete();

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé avec succès.',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Helpers privés
    // ─────────────────────────────────────────────────────────

    private function restaurantData(Restaurant $restaurant): array
    {
        return [
            'id'                => $restaurant->id,
            'uuid'              => $restaurant->uuid,
            'name'              => $restaurant->name,
            'category'          => $restaurant->category,
            'email'             => $restaurant->email,
            'phone'             => $restaurant->phone,
            'address'           => $restaurant->address,
            'city'              => $restaurant->city,
            'country'           => $restaurant->country,
            'description'       => $restaurant->description,
            'logo_url'          => $restaurant->logo_url,
            'whatsapp'          => $restaurant->whatsapp,
            'instagram'         => $restaurant->instagram,
            'facebook'          => $restaurant->facebook,
            'tiktok'            => $restaurant->tiktok,
            'qr_token'          => $restaurant->qr_token,
            'short_code'        => $restaurant->short_code,
            'has_business_info'   => $restaurant->hasBusinessInfo(),
            'latitude'            => $restaurant->location?->latitude,
            'longitude'           => $restaurant->location?->longitude,
            'has_location'        => $restaurant->hasLocation(),
            'has_loyalty_program' => $restaurant->hasLoyaltyProgram(),
            // Config du programme (couleurs, mode, objectif, style de tampon) :
            // le dashboard marchand la rejoue telle quelle, sans second appel.
            'loyalty_program'     => $restaurant->loyaltyProgram
                ? [
                    'type'   => $restaurant->loyaltyProgram->type,
                    'config' => $restaurant->loyaltyProgram->config,
                ]
                : null,
            'plan'                => $restaurant->planSlug(),
            'sms_credits'         => (int) $restaurant->sms_credits,
            'created_at'        => $restaurant->created_at?->toIso8601String(),
        ];
    }
}
