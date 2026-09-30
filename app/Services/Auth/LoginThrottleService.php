<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginThrottleService
{
    /**
     * Nombre maximum de tentatives avant verrouillage.
     */
    public static function maxAttempts(): int
    {
        return (int) config('auth.throttle.max_attempts', 5);
    }

    /**
     * Durée en secondes du verrouillage.
     */
    public static function decaySeconds(): int
    {
        return (int) config('auth.throttle.decay_seconds', 60);
    }

    /**
     * Vérifie si le compte (ou l'IP) a dépassé la limite de tentatives.
     *
     * @throws ValidationException
     */
    public static function ensureIsNotRateLimited(string $type, string $identifier, ?string $ip = null): void
    {
        $accountKey = self::accountKey($type, $identifier);

        if (RateLimiter::tooManyAttempts($accountKey, self::maxAttempts())) {
            $seconds = RateLimiter::availableIn($accountKey);

            $field = match ($type) {
                'client' => 'phone',
                'restaurant', 'staff' => 'email',
                default => 'identifier',
            };

            throw ValidationException::withMessages([
                $field => [
                    "Trop de tentatives. Réessayez dans {$seconds} secondes.",
                ],
            ])->status(429);
        }

        if ($ip) {
            $ipKey = "login:ip:{$ip}";
            if (RateLimiter::tooManyAttempts($ipKey, 30)) {
                $seconds = RateLimiter::availableIn($ipKey);

                throw ValidationException::withMessages([
                    'ip' => [
                        "Trop de tentatives depuis votre adresse IP. Réessayez dans {$seconds} secondes.",
                    ],
                ])->status(429);
            }
        }
    }

    /**
     * Enregistre une tentative de connexion échouée.
     */
    public static function hit(string $type, string $identifier, ?string $ip = null): void
    {
        $accountKey = self::accountKey($type, $identifier);
        RateLimiter::hit($accountKey, self::decaySeconds());

        if ($ip) {
            $compositeKey = self::compositeKey($type, $identifier, $ip);
            RateLimiter::hit($compositeKey, self::decaySeconds());

            $ipKey = "login:ip:{$ip}";
            RateLimiter::hit($ipKey, 60);
        }
    }

    /**
     * Réinitialise le compteur après une connexion réussie.
     */
    public static function clear(string $type, string $identifier, ?string $ip = null): void
    {
        self::unlock($type, $identifier, $ip);
    }

    /**
     * Débloque et réinitialise tous les verrous de connexion pour cet identifiant.
     */
    public static function unlock(string $type, string $identifier, ?string $ip = null): void
    {
        $cleanIdentifier = self::normalizeIdentifier($identifier);
        $accountKey = self::accountKey($type, $identifier);

        RateLimiter::clear($accountKey);
        RateLimiter::clear($cleanIdentifier);

        if ($ip) {
            $compositeKey = self::compositeKey($type, $identifier, $ip);
            RateLimiter::clear($compositeKey);
        }

        // Nettoyage complet dans la table de cache si le driver est SQL (DB cache)
        try {
            if (Schema::hasTable('cache')) {
                DB::table('cache')
                    ->where('key', 'LIKE', '%' . $cleanIdentifier . '%')
                    ->delete();
            }
        } catch (\Throwable $e) {
            // Ignorer si table absente ou driver cache en mémoire/test
        }
    }

    /**
     * Vérifie si le compte est actuellement verrouillé.
     */
    public static function isLocked(string $type, ?string $identifier): bool
    {
        if (blank($identifier)) {
            return false;
        }

        $accountKey = self::accountKey($type, $identifier);

        return RateLimiter::tooManyAttempts($accountKey, self::maxAttempts());
    }

    /**
     * Nombre de tentatives échouées actuelles.
     */
    public static function attempts(string $type, ?string $identifier): int
    {
        if (blank($identifier)) {
            return 0;
        }

        $accountKey = self::accountKey($type, $identifier);

        return RateLimiter::attempts($accountKey);
    }

    /**
     * Nombre de secondes restantes avant expiration du verrou.
     */
    public static function availableIn(string $type, ?string $identifier): int
    {
        if (blank($identifier)) {
            return 0;
        }

        $accountKey = self::accountKey($type, $identifier);

        return RateLimiter::availableIn($accountKey);
    }

    /**
     * Clé par compte (indépendante de l'IP, permet le déblocage Filament).
     */
    public static function accountKey(string $type, string $identifier): string
    {
        return "login:{$type}:" . self::normalizeIdentifier($identifier);
    }

    /**
     * Clé composite (avec IP pour rétrocompatibilité).
     */
    public static function compositeKey(string $type, string $identifier, string $ip): string
    {
        return self::normalizeIdentifier($identifier) . '|' . $ip;
    }

    /**
     * Normalise l'identifiant (téléphone ou email).
     */
    public static function normalizeIdentifier(string $identifier): string
    {
        return Str::lower(trim($identifier));
    }
}
