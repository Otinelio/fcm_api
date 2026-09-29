<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProximitySettingsRequest;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProximitySettingsController extends Controller
{
    /**
     * GET /api/merchant/proximity-settings
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $settings = $restaurant->proximitySettings();
        $planAllowsGeo = $restaurant->plan ? (bool) $restaurant->plan->allows_geolocation : true;

        return response()->json([
            'settings' => $settings,
            'is_active' => $restaurant->isProximityActive(),
            'has_location' => $restaurant->hasLocation(),
            'latitude' => $restaurant->location?->latitude,
            'longitude' => $restaurant->location?->longitude,
            'plan_allows_geolocation' => $planAllowsGeo,
            'cooldown_hours' => 24,
        ]);
    }

    /**
     * PUT /api/merchant/proximity-settings
     */
    public function update(UpdateProximitySettingsRequest $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $validated = $request->validated();

        $current = $restaurant->proximitySettings();

        $newSettings = [
            'enabled' => (bool) $validated['enabled'],
            'radius_m' => (int) $validated['radius_m'],
            'title' => $validated['title'] ?? $current['title'],
            'message' => $validated['message'] ?? $current['message'],
            'cooldown_hours' => 24, // Fixe à 24h selon la décision produit validée
        ];

        $restaurant->update([
            'proximity_settings' => $newSettings,
        ]);

        return response()->json([
            'message' => 'Paramètres de notifications de proximité enregistrés.',
            'settings' => $restaurant->proximitySettings(),
            'is_active' => $restaurant->isProximityActive(),
        ]);
    }
}
