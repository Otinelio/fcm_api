<?php

namespace App\Services\Auth;

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use InvalidArgumentException;
use Kreait\Firebase\JWT\IdTokenVerifier;
use Kreait\Firebase\JWT\Error\IdTokenVerificationFailed;

class SocialAuthService
{
    // ─────────────────────────────────────────────────────────
    // Firebase ID Token Validation (remplace Google ID Token direct)
    // ─────────────────────────────────────────────────────────

    /**
     * Valide un ID token Firebase et retourne les données utilisateur.
     *
     * @return array{sub: string, email: string|null, name: string|null, given_name: string|null, family_name: string|null, picture: string|null}
     * @throws InvalidArgumentException
     */
    public function validateFirebaseToken(string $idToken): array
    {
        $projectId = config('services.firebase.project_id');
        
        if (empty($projectId)) {
            throw new \RuntimeException('Erreur serveur : FIREBASE_PROJECT_ID non configuré.');
        }

        $verifier = IdTokenVerifier::createWithProjectId($projectId);

        try {
            $token = $verifier->verifyIdToken($idToken);
            $payload = $token->payload();

            return [
                'sub'         => $payload['sub'], // C'est l'UID Firebase de l'utilisateur
                'email'       => $payload['email'] ?? null,
                'name'        => $payload['name'] ?? null,
                'given_name'  => $payload['name'] ?? null, // Firebase JWT regroupe souvent nom/prénom dans 'name'
                'family_name' => null,
                'picture'     => $payload['picture'] ?? null,
            ];
        } catch (IdTokenVerificationFailed $e) {
            throw new InvalidArgumentException('Token Firebase invalide ou expiré : ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // Apple ID Token Validation
    // ─────────────────────────────────────────────────────────

    /**
     * Valide un ID token Apple et retourne les données utilisateur.
     *
     * Apple Sign In envoie un JWT signé avec les clés publiques Apple.
     * On récupère les clés JWKS, on décode et valide le JWT.
     *
     * @return array{sub: string, email: string|null, name: string|null}
     * @throws InvalidArgumentException
     */
    public function validateAppleToken(string $idToken): array
    {
        // Récupérer les clés publiques Apple (cachées 1 heure)
        $jwks = Cache::remember('apple_jwks', 3600, function () {
            $response = Http::get('https://appleid.apple.com/auth/keys');

            if ($response->failed()) {
                throw new InvalidArgumentException('Impossible de récupérer les clés Apple.');
            }

            return $response->json();
        });

        try {
            // Décoder le JWT avec les clés publiques Apple
            $keys = JWK::parseKeySet($jwks, 'RS256');
            $decoded = JWT::decode($idToken, $keys);

            // Vérifier l'issuer et l'audience
            if ($decoded->iss !== 'https://appleid.apple.com') {
                throw new InvalidArgumentException('Issuer Apple invalide.');
            }

            $expectedAudience = config('services.apple.client_id');
            if ($decoded->aud !== $expectedAudience) {
                throw new InvalidArgumentException('Audience Apple invalide.');
            }

            // Vérifier l'expiration
            if ($decoded->exp < time()) {
                throw new InvalidArgumentException('Token Apple expiré.');
            }

            return [
                'sub'   => $decoded->sub,
                'email' => $decoded->email ?? null,
                'name'  => null, // Apple ne renvoie le nom que lors de la première connexion (via le SDK)
            ];
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException('Token Apple invalide : ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // Find or Create Client
    // ─────────────────────────────────────────────────────────

    /**
     * Cherche un client existant par OAuth ID ou email, ou en crée un nouveau.
     *
     * @return array{client: Client, is_new: bool}
     */
    public function findOrCreateClient(
        string $provider,
        string $oauthId,
        ?string $email = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $avatarUrl = null,
        bool $allowCreation = true,
    ): array {
        // 1. Chercher par oauth_provider + oauth_id (lien direct)
        $client = Client::where('oauth_provider', $provider)
            ->where('oauth_id', $oauthId)
            ->first();

        if ($client) {
            return ['client' => $client, 'is_new' => false];
        }

        // 2. Si email fourni, chercher par email (compte existant à lier)
        if ($email) {
            $client = Client::where('email', $email)->first();

            if ($client) {
                // Lier le compte OAuth au client existant
                $client->update([
                    'oauth_provider' => $provider,
                    'oauth_id'       => $oauthId,
                    'avatar_url'     => $client->avatar_url ?? $avatarUrl,
                ]);

                return ['client' => $client, 'is_new' => false];
            }
        }

        // 3. Créer un nouveau client (profil partiel — sera complété à l'étape 2)
        if (!$allowCreation) {
            throw new \InvalidArgumentException('Aucun compte n\'est associé à cette adresse email.');
        }

        $client = Client::create([
            'uuid'           => (string) Str::uuid(),
            'first_name'     => $firstName ?? '',
            'last_name'      => $lastName,
            'email'          => $email,
            'phone'          => null, // Sera complété dans completeSocialProfile
            'password'       => null, // Utilisateurs OAuth n'ont pas de mot de passe
            'avatar_url'     => $avatarUrl,
            'oauth_provider' => $provider,
            'oauth_id'       => $oauthId,
        ]);

        return ['client' => $client, 'is_new' => true];
    }

    // ─────────────────────────────────────────────────────────
    // Public dispatcher
    // ─────────────────────────────────────────────────────────

    /**
     * Valide un token en fonction du provider.
     *
     * @return array{sub: string, email: string|null, name: string|null, ...}
     * @throws InvalidArgumentException
     */
    public function validateToken(string $provider, string $idToken): array
    {
        return match ($provider) {
            'google' => $this->validateFirebaseToken($idToken),
            'apple'  => $this->validateAppleToken($idToken),
            default  => throw new InvalidArgumentException("Provider non supporté : {$provider}"),
        };
    }
}
