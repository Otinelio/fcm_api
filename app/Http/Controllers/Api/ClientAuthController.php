<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteSocialProfileRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\SocialLoginRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\UpdateAvatarRequest;
use App\Models\Client;
use App\Services\Auth\SocialAuthService;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\VerifyResetOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\ValidateRegisterStep1Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuth,
    ) {}

    // ─────────────────────────────────────────────────────────
    // Register (phone + password)
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/validate-register-step1
     *
     * Valide les données de la première étape de l'inscription (nom, téléphone, date de naissance).
     */
    public function validateRegisterStep1(ValidateRegisterStep1Request $request): JsonResponse
    {
        // Si on arrive ici, c'est que la validation FormRequest a réussi (y compris le FraudRiskService)
        return response()->json([
            'message' => 'Les informations de l\'étape 1 sont valides.'
        ]);
    }

    /**
     * POST /api/auth/register
     *
     * Inscription classique : first_name, phone, password (+confirmation),
     * birthdate (optionnel), city (optionnel), referral_code (optionnel).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Gérer le parrainage
        $referredByClientId = null;
        if (! empty($data['referral_code'])) {
            $referrer = Client::where('referral_code', $data['referral_code'])->first();
            $referredByClientId = $referrer?->id;
        }

        $client = Client::create([
            'uuid'                  => (string) Str::uuid(),
            'first_name'            => $data['first_name'],
            'phone'                 => $data['phone'],
            'password'              => $data['password'], // Cast 'hashed' dans le modèle
            'birthdate'             => $data['birthdate'] ?? null,
            'city'                  => $data['city'] ?? null,
            'country'               => $data['country'] ?? null,
            'referral_code'         => $this->generateReferralCode(),
            'referred_by_client_id' => $referredByClientId,
        ]);

        $token = $client->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'message'      => 'Inscription réussie.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'client'       => $this->clientData($client),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────
    // Login (phone + password)
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/login
     *
     * Connexion classique par téléphone + mot de passe.
     * Rate limited : 5 tentatives/minute par IP+phone.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $client = Client::where('phone', $request->phone)->first();

        if (! $client) {
            $request->hitRateLimiter();

            return response()->json([
                'message' => 'Aucun compte n\'est associé à ce numéro.',
            ], 401);
        }

        if ($client->isOAuthUser()) {
            $request->hitRateLimiter();

            return response()->json([
                'message' => $client->authMethodDeniedMessage(),
            ], 403);
        }

        if (! Hash::check($request->password, $client->password)) {
            $request->hitRateLimiter();

            return response()->json([
                'message' => 'Mot de passe incorrect.',
            ], 401);
        }

        $request->clearRateLimiter();

        $token = $client->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'message'      => 'Connexion réussie.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'client'       => $this->clientData($client),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Social Login (Google / Apple) — Étape 1
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/social
     *
     * Reçoit un id_token du SDK Google/Apple côté Flutter,
     * le valide côté serveur, et :
     *  - Si le client existe → retourne le token + profil
     *  - Si nouveau client → crée un profil partiel et retourne
     *    needs_profile_completion: true
     */
    public function socialLogin(SocialLoginRequest $request): JsonResponse
    {
        try {
            // Valider le token auprès du provider
            $oauthData = $this->socialAuth->validateToken(
                $request->provider,
                $request->id_token,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 401);
        }

        $action = $request->input('action', 'login');
        $allowCreation = ($action === 'signup');

        try {
            // Trouver ou créer le client
            ['client' => $client, 'is_new' => $isNew] = $this->socialAuth->findOrCreateClient(
                provider: $request->provider,
                oauthId: $oauthData['sub'],
                email: $oauthData['email'] ?? null,
                firstName: $oauthData['given_name'] ?? $oauthData['name'] ?? $request->name ?? null,
                lastName: $oauthData['family_name'] ?? null,
                avatarUrl: $oauthData['picture'] ?? null,
                allowCreation: $allowCreation,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        }

        $token = $client->createToken('mobile-app')->plainTextToken;

        // Si le client est nouveau et que le profil est incomplet (pas de phone/nom),
        // on signale au frontend qu'il doit compléter le profil.
        $needsCompletion = $isNew || ! $client->isProfileComplete();

        return response()->json([
            'message'                   => $isNew ? 'Compte créé via ' . $request->provider . '.' : 'Connexion réussie.',
            'access_token'              => $token,
            'token_type'                => 'Bearer',
            'needs_profile_completion'  => $needsCompletion,
            'client'                    => $this->clientData($client),
        ], $isNew ? 201 : 200);
    }

    // ─────────────────────────────────────────────────────────
    // Complete Social Profile — Étape 2
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/social/complete-profile
     *
     * Après un OAuth, le frontend envoie les infos manquantes :
     * first_name, phone, birthdate.
     *
     * Nécessite un token Sanctum (obtenu à l'étape 1).
     */
    public function completeSocialProfile(CompleteSocialProfileRequest $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        $data = [
            'first_name'    => $request->first_name,
            'phone'         => $request->phone,
            'birthdate'     => $request->birthdate,
            'referral_code' => $client->referral_code ?? $this->generateReferralCode(),
        ];

        // Champs optionnels envoyés par le front
        if ($request->filled('city'))    $data['city']    = $request->city;
        if ($request->filled('country')) $data['country'] = $request->country;
        if ($request->filled('email'))   $data['email']   = $request->email;

        $client->update($data);

        return response()->json([
            'message' => 'Profil complété avec succès.',
            'client'  => $this->clientData($client->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Me (profil du client connecté)
    // ─────────────────────────────────────────────────────────

    /**
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        /** @var Client $client */
        $client = auth()->user();

        return response()->json([
            'client' => $this->clientData($client),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Update Profile
    // ─────────────────────────────────────────────────────────

    /**
     * PUT /api/auth/profile
     *
     * Mise à jour partielle du profil client (email, city, avatar, etc.).
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        $client->update($request->validated());

        return response()->json([
            'message' => 'Profil mis à jour.',
            'client'  => $this->clientData($client->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Avatar
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/profile/avatar
     *
     * Upload (ou remplace) la photo de profil du client.
     */
    public function uploadAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        // Un ré-upload peut changer de format (png -> jpg) : on nettoie toute
        // ancienne extension avant d'écrire la nouvelle, sinon l'ancien fichier
        // reste orphelin sur le disque.
        $this->purgeAvatarFiles($client);

        $extension = $request->file('avatar')->extension();
        $path = $request->file('avatar')->storeAs('avatars', "{$client->uuid}.{$extension}", 'public');

        if ($path === false) {
            return response()->json([
                'message' => 'Échec de l\'enregistrement de la photo. Réessayez.',
            ], 500);
        }

        // Le chemin est déterministe (avatars/{uuid}.{ext}) : un ré-upload avec la même
        // extension produirait la même URL, ce qui ferait servir l'image en cache côté
        // client (Image.network / CDN). On ajoute un paramètre de cache-busting.
        $client->update(['avatar_url' => asset(Storage::url($path)) . '?v=' . now()->timestamp]);

        return response()->json([
            'message' => 'Photo de profil mise à jour.',
            'client'  => $this->clientData($client->fresh()),
        ]);
    }

    /**
     * DELETE /api/auth/profile/avatar
     *
     * Supprime la photo de profil du client (retour à l'avatar par défaut).
     */
    public function deleteAvatar(\Illuminate\Http\Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        $this->purgeAvatarFiles($client);
        $client->update(['avatar_url' => null]);

        return response()->json([
            'message' => 'Photo de profil supprimée.',
            'client'  => $this->clientData($client->fresh()),
        ]);
    }

    /**
     * Supprime tous les fichiers avatar connus pour ce client, toutes extensions
     * confondues (utilisé avant un ré-upload et lors de la suppression).
     */
    private function purgeAvatarFiles(Client $client): void
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            Storage::disk('public')->delete("avatars/{$client->uuid}.{$ext}");
        }
    }

    // ─────────────────────────────────────────────────────────
    // Logout
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/logout
     *
     * Révoque le token Sanctum courant.
     */
    public function logout(): JsonResponse
    {
        /** @var Client $client */
        $client = auth()->user();

        // Révoquer uniquement le token utilisé pour cette requête
        $client->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Helpers privés
    // ─────────────────────────────────────────────────────────

    /**
     * Formatte les données client pour la réponse JSON.
     */
    private function clientData(Client $client): array
    {
        return [
            'id'             => $client->id,
            'uuid'           => $client->uuid,
            'first_name'     => $client->first_name,
            'last_name'      => $client->last_name,
            'phone'          => $client->phone,
            'email'          => $client->email,
            'birthdate'      => $client->birthdate?->format('Y-m-d'),
            'city'           => $client->city,
            'country'        => $client->country,
            'avatar_url'     => $client->avatar_url,
            'referral_code'  => $client->referral_code,
            'oauth_provider' => $client->oauth_provider,
            'is_profile_complete' => $client->isProfileComplete(),
            // Date d'inscription — alimente le "Membre depuis" du profil côté
            // mobile, qui retombait sinon sur la date du jour à chaque appel.
            'created_at'     => $client->created_at?->toIso8601String(),
        ];
    }

    /**
     * Génère un code de parrainage unique (8 caractères alphanumérique majuscule).
     */
    private function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Client::where('referral_code', $code)->exists());

        return $code;
    }

    // ─────────────────────────────────────────────────────────
    // Password Recovery
    // ─────────────────────────────────────────────────────────

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $identifier = $request->phone ?? $request->email;

        $client = Client::where(isset($request->phone) ? 'phone' : 'email', $identifier)->first();

        if ($client && $client->isOAuthUser()) {
            return response()->json([
                'message' => $client->authMethodDeniedMessage(),
            ], 403);
        }

        $otp = (string) random_int(100000, 999999);

        Cache::put('otp_reset_' . $identifier, $otp, now()->addMinutes(10));

        // Simuler l'envoi pour les tests locaux (vu qu'on n'a pas de SMS Gateway)
        Log::info("Code OTP de réinitialisation pour {$identifier} : {$otp}");

        $response = ['message' => 'Un code de réinitialisation a été envoyé.'];

        // En mode debug, on renvoie l'OTP dans la réponse pour faciliter les tests
        if (config('app.debug')) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response);
    }

    public function verifyResetOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $identifier = $request->phone ?? $request->email;
        $cachedOtp = Cache::get('otp_reset_' . $identifier);

        if (! $cachedOtp || $cachedOtp !== $request->otp) {
            return response()->json([
                'message' => 'Le code de réinitialisation est invalide ou a expiré.',
            ], 400);
        }

        // OTP valide, on génère un token de réinitialisation
        $resetToken = (string) Str::uuid();
        Cache::put('reset_token_' . $identifier, $resetToken, now()->addMinutes(15));

        // On supprime l'OTP utilisé
        Cache::forget('otp_reset_' . $identifier);

        return response()->json([
            'message'     => 'Code vérifié avec succès.',
            'reset_token' => $resetToken,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $identifier = $request->phone ?? $request->email;
        $cachedToken = Cache::get('reset_token_' . $identifier);

        if (! $cachedToken || $cachedToken !== $request->reset_token) {
            return response()->json([
                'message' => 'La session de réinitialisation a expiré. Veuillez recommencer.',
            ], 400);
        }

        // Trouver le client
        $client = Client::where(isset($request->phone) ? 'phone' : 'email', $identifier)->firstOrFail();

        if ($client->isOAuthUser()) {
            return response()->json([
                'message' => $client->authMethodDeniedMessage(),
            ], 403);
        }

        // Mettre à jour le mot de passe
        $client->update([
            'password' => Hash::make($request->password),
        ]);

        // Invalider le token
        Cache::forget('reset_token_' . $identifier);

        // Révoquer tous ses tokens actuels pour le forcer à se reconnecter
        $client->tokens()->delete();

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé avec succès.',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Verify Current Password (authenticated)
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/verify-password
     *
     * Vérifie que le mot de passe fourni correspond bien au mot de passe actuel.
     * Utilisé comme première étape du flux de changement de mot de passe.
     */
    public function verifyPassword(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
        ]);

        /** @var Client $client */
        $client = $request->user();

        if (! Hash::check($request->current_password, $client->password)) {
            return response()->json([
                'message' => 'Le mot de passe est incorrect.',
                'valid'   => false,
            ], 422);
        }

        return response()->json([
            'message' => 'Mot de passe vérifié.',
            'valid'   => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Change Password (authenticated)
    // ─────────────────────────────────────────────────────────

    /**
     * PUT /api/auth/change-password
     *
     * Permet au client connecté de modifier son mot de passe.
     * Requiert : current_password, password, password_confirmation.
     */
    public function changePassword(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'current_password'      => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        /** @var Client $client */
        $client = $request->user();

        if (! Hash::check($request->current_password, $client->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        if (Hash::check($request->password, $client->password)) {
            return response()->json([
                'message' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
            ], 422);
        }

        $client->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Votre mot de passe a été modifié avec succès.',
        ]);
    }
}
