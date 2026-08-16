<?php

namespace App\Services\Auth;

use App\Models\Client;
use App\Models\Restaurant;
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
            // La tolérance d'horloge évite les rejets « token used too early »
            // quand l'horloge du serveur dérive de quelques secondes.
            $token = $verifier->verifyIdTokenWithLeeway(
                $idToken,
                (int) config('services.firebase.leeway', 60),
            );
            $payload = $token->payload();
        } catch (IdTokenVerificationFailed $e) {
            throw new InvalidArgumentException('Token Firebase invalide ou expiré : ' . $e->getMessage());
        }

        if (empty($payload['sub'])) {
            throw new InvalidArgumentException('Token Firebase sans identifiant utilisateur (sub).');
        }

        // Firebase place le nom complet dans `name`. Google renseigne en plus
        // `given_name`/`family_name` dans `firebase.identities`, mais pas
        // toujours : on découpe `name` en dernier recours pour ne pas créer un
        // client dont le prénom contient le nom entier.
        $fullName   = $payload['name'] ?? null;
        $givenName  = $payload['given_name'] ?? null;
        $familyName = $payload['family_name'] ?? null;

        if ($givenName === null && $fullName !== null) {
            $parts      = preg_split('/\s+/', trim($fullName), 2);
            $givenName  = $parts[0] ?? null;
            $familyName = $familyName ?? ($parts[1] ?? null);
        }

        return [
            'sub'         => $payload['sub'], // C'est l'UID Firebase de l'utilisateur
            'email'       => $payload['email'] ?? null,
            'name'        => $fullName,
            'given_name'  => $givenName,
            'family_name' => $familyName,
            'picture'     => $payload['picture'] ?? null,
        ];
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

        $expectedAudience = config('services.apple.client_id');

        if (empty($expectedAudience)) {
            throw new \RuntimeException('Erreur serveur : APPLE_CLIENT_ID non configuré.');
        }

        try {
            // Décoder le JWT avec les clés publiques Apple
            $keys = JWK::parseKeySet($jwks, 'RS256');

            // Même tolérance d'horloge que pour Firebase.
            JWT::$leeway = (int) config('services.firebase.leeway', 60);
            $decoded = JWT::decode($idToken, $keys);

            // Vérifier l'issuer et l'audience
            if ($decoded->iss !== 'https://appleid.apple.com') {
                throw new InvalidArgumentException('Issuer Apple invalide.');
            }

            // `aud` est une chaîne pour le flux natif, mais Apple peut renvoyer
            // un tableau ; comparer directement rejetterait un jeton valide.
            $audiences = is_array($decoded->aud) ? $decoded->aud : [$decoded->aud];
            if (! in_array($expectedAudience, $audiences, true)) {
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

        // 2. Si un compte existe déjà avec cet email, la méthode d'authentification
        // de ce compte reste inchangée : on ne lie jamais silencieusement une
        // identité OAuth à un compte trouvé par email (voir Client::authMethodDeniedMessage
        // — la méthode associée au compte est la seule source de vérité).
        if ($email) {
            $existing = Client::where('email', $email)->first();

            if ($existing) {
                throw new \InvalidArgumentException($existing->authMethodDeniedMessage());
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
    // Find or Create Restaurant (marchand)
    // ─────────────────────────────────────────────────────────

    /**
     * Cherche un restaurant existant par OAuth ID ou email, ou en crée un nouveau.
     *
     * Contrairement à [findOrCreateClient], on ne renseigne pas `name` depuis le
     * profil Google/Apple : c'est le nom du commerce (pas de la personne), il
     * reste à saisir au step1 comme pour l'inscription email+password.
     *
     * @return array{restaurant: Restaurant, is_new: bool}
     */
    public function findOrCreateRestaurant(
        string $provider,
        string $oauthId,
        ?string $email = null,
        bool $allowCreation = true,
    ): array {
        $restaurant = Restaurant::where('oauth_provider', $provider)
            ->where('oauth_id', $oauthId)
            ->first();

        if ($restaurant) {
            return ['restaurant' => $restaurant, 'is_new' => false];
        }

        // Un compte trouvé par email garde sa méthode d'authentification
        // d'origine : jamais de liaison silencieuse à une identité OAuth.
        if ($email) {
            $existing = Restaurant::where('email', $email)->first();

            if ($existing) {
                throw new \InvalidArgumentException($existing->authMethodDeniedMessage());
            }
        }

        if (!$allowCreation) {
            throw new \InvalidArgumentException('Aucun compte n\'est associé à cette adresse email.');
        }

        $restaurant = Restaurant::create([
            'email'          => $email,
            'password'       => null, // Comptes OAuth : pas de mot de passe
            'oauth_provider' => $provider,
            'oauth_id'       => $oauthId,
        ]);

        return ['restaurant' => $restaurant, 'is_new' => true];
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
