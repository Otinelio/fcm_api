<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class StorageUrlResolver
{
    /**
     * Résout une URL publique d'asset de manière dynamique et robuste.
     *
     * Si l'URL stockée en base contient une référence au stockage local (/storage/),
     * cette méthode remplace automatiquement l'ancien hôte (ex: ngrok expiré, localhost,
     * ancienne IP LAN) par l'hôte de la requête HTTP active (ex: 192.168.1.83 ou domaine de prod).
     *
     * Si l'URL est externe (ex: avatar Google, CDN tiers), elle est conservée telle quelle.
     *
     * @param  string|null  $urlOrPath
     * @return string|null
     */
    public static function resolve(?string $urlOrPath): ?string
    {
        if ($urlOrPath === null || trim($urlOrPath) === '') {
            return null;
        }

        $urlOrPath = trim($urlOrPath);

        // 1. Détecter si l'URL pointe vers un fichier du stockage public local
        $isLocalStorage = false;
        $relativePath = '';
        $query = '';

        if (str_contains($urlOrPath, '/storage/')) {
            $isLocalStorage = true;
            $parts = explode('/storage/', $urlOrPath, 2);
            $relativePath = $parts[1] ?? '';
        } elseif (! str_starts_with($urlOrPath, 'http://') && ! str_starts_with($urlOrPath, 'https://')) {
            // Chemin relatif direct (ex: "logos/uuid.jpg" ou "storage/logos/uuid.jpg")
            $isLocalStorage = true;
            $relativePath = ltrim($urlOrPath, '/');
            if (str_starts_with($relativePath, 'storage/')) {
                $relativePath = substr($relativePath, 8);
            }
        }

        // Si ce n'est pas un fichier local (ex: avatar Google https://lh3.googleusercontent.com/...), retourner tel quel
        if (! $isLocalStorage) {
            return $urlOrPath;
        }

        // Séparer les paramètres d'URL éventuels (?v=12345)
        if (str_contains($relativePath, '?')) {
            [$relativePath, $queryStr] = explode('?', $relativePath, 2);
            $query = '?' . $queryStr;
        }

        $relativePath = ltrim($relativePath, '/');

        // Construire l'URL avec l'hôte de la requête HTTP en cours
        if (app()->bound('request') && request()->getHttpHost()) {
            $scheme = request()->getScheme() ?: 'http';
            $host = request()->getHttpHost();
            return "{$scheme}://{$host}/storage/{$relativePath}{$query}";
        }

        // Contexte console / worker / tests sans requête HTTP active
        return url('/storage/' . $relativePath) . $query;
    }
}
