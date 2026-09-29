<?php

namespace App\Support;

use App\Models\Advertisement;
use App\Models\Client;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;

class AvatarHelper
{
    /**
     * Palettes de dégradés modernes (style SaaS Tailwind).
     */
    protected static array $palettes = [
        ['#4f46e5', '#7c3aed'], // Indigo -> Violet
        ['#0284c7', '#2563eb'], // Sky -> Blue
        ['#059669', '#0d9488'], // Emerald -> Teal
        ['#d97706', '#ea580c'], // Amber -> Orange
        ['#e11d48', '#db2777'], // Rose -> Pink
        ['#7c3aed', '#9333ea'], // Violet -> Purple
        ['#0891b2', '#0284c7'], // Cyan -> Sky
        ['#4338ca', '#6366f1'], // Deep Indigo
    ];

    /**
     * Génère un avatar SVG en Data URI pour un client.
     */
    public static function forClient(?Client $client, int $size = 80): string
    {
        if ($client && $client->avatar_url) {
            return $client->avatar_url;
        }

        $name = $client?->full_name ?: ($client?->email ?: 'Client');
        $initials = self::extractInitials($name);

        return self::generateCircleSvg($name, $initials, $size);
    }

    /**
     * Génère un logo / avatar SVG en Data URI pour un restaurant.
     */
    public static function forRestaurant(?Restaurant $restaurant, int $size = 80): string
    {
        if ($restaurant && $restaurant->logo_url) {
            return $restaurant->logo_url;
        }

        $name = $restaurant?->name ?: 'Restaurant';
        $initials = self::extractInitials($name);

        return self::generateCircleSvg($name, $initials, $size);
    }

    /**
     * Génère un avatar SVG pour un utilisateur administrateur ou staff.
     */
    public static function forUser(?string $name, int $size = 80): string
    {
        $label = $name ?: 'Admin';
        $initials = self::extractInitials($label);

        return self::generateCircleSvg($label, $initials, $size);
    }

    /**
     * Génère un placeholder SVG au format bannière 16:9 pour une publicité sans visuel.
     */
    public static function forAdvertisement(?Advertisement $ad, int $width = 96, int $height = 54): string
    {
        if ($ad && $ad->image_url) {
            return $ad->image_url;
        }

        $title = $ad?->title ?: 'Pub';
        $palette = self::getPalette($title);
        $startColor = $palette[0];
        $endColor = $palette[1];

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <defs>
    <linearGradient id="grad-ad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$startColor}" />
      <stop offset="100%" stop-color="{$endColor}" />
    </linearGradient>
  </defs>
  <rect width="{$width}" height="{$height}" rx="6" fill="url(#grad-ad)" />
  <g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" transform="translate(36, 15) scale(0.9)">
    <path d="M11 5L6 9H2v6h4l5 4V5z"/>
    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
  </g>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }

    /**
     * Génère un placeholder SVG pour une campagne de notification.
     */
    public static function forCampaign(?NotificationCampaign $campaign, int $width = 56, int $height = 38): string
    {
        if ($campaign && $campaign->image_url) {
            return $campaign->image_url;
        }

        $title = $campaign?->title ?: 'Push';
        $palette = self::getPalette($title);
        $startColor = $palette[0];
        $endColor = $palette[1];

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <defs>
    <linearGradient id="grad-camp" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$startColor}" />
      <stop offset="100%" stop-color="{$endColor}" />
    </linearGradient>
  </defs>
  <rect width="{$width}" height="{$height}" rx="6" fill="url(#grad-camp)" />
  <g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" transform="translate(18, 9) scale(0.85)">
    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h24s-3-2-3-9"/>
    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
  </g>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }

    /**
     * Génère un avatar circulaire avec initiales et dégradé.
     */
    protected static function generateCircleSvg(string $name, string $initials, int $size): string
    {
        $palette = self::getPalette($name);
        $startColor = $palette[0];
        $endColor = $palette[1];
        $radius = (int) round($size / 2);
        $fontSize = (int) round($size * 0.42);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
  <defs>
    <linearGradient id="grad-{$radius}" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$startColor}" />
      <stop offset="100%" stop-color="{$endColor}" />
    </linearGradient>
  </defs>
  <rect width="{$size}" height="{$size}" rx="{$radius}" fill="url(#grad-{$radius})" />
  <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" fill="#ffffff" font-size="{$fontSize}" font-family="system-ui, -apple-system, sans-serif" font-weight="700" letter-spacing="-0.5px">{$initials}</text>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }

    /**
     * Extrait 1 ou 2 initiales à partir d'un texte.
     */
    public static function extractInitials(?string $text): string
    {
        if (! $text || trim($text) === '') {
            return '?';
        }

        $cleaned = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text));
        $words = preg_split('/\s+/u', $cleaned, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return mb_strtoupper(mb_substr($text, 0, 1));
        }

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    }

    /**
     * Choisit une palette de manière déterministe en fonction du texte.
     */
    protected static function getPalette(string $key): array
    {
        $index = abs(crc32($key)) % count(self::$palettes);

        return self::$palettes[$index];
    }
}
