<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialRedirectController extends Controller
{
    /**
     * Redirige directement vers le réseau social configuré pour l'établissement.
     * Accessible via GET /r/{identifier}/{platform}
     */
    public function redirect(Request $request, string $identifier, string $platform): RedirectResponse
    {
        $query = Restaurant::query();

        if (\Illuminate\Support\Str::isUuid($identifier)) {
            $query->where('uuid', $identifier);
        } elseif (ctype_digit($identifier)) {
            $query->where('id', (int) $identifier);
        } else {
            $query->where('short_code', strtoupper($identifier));
        }

        $restaurant = $query->first();

        if (! $restaurant) {
            abort(404, 'Établissement introuvable.');
        }

        $platform = strtolower($platform);
        $allowedPlatforms = ['facebook', 'instagram', 'tiktok', 'whatsapp', 'website', 'x', 'twitter', 'youtube', 'snapchat', 'linkedin', 'tripadvisor', 'google'];

        if (! in_array($platform, $allowedPlatforms, true)) {
            abort(404, "Plateforme '{$platform}' non supportée.");
        }

        $url = $restaurant->getSocialRedirectUrl($platform);

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            abort(404, "Le profil {$platform} de cet établissement n'est pas configuré.");
        }

        return redirect()->away($url, 302);
    }
}
