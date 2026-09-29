<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Proximity\ProximityNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProximityController extends Controller
{
    public function __construct(
        protected ProximityNotificationService $proximityService
    ) {}

    /**
     * POST /api/client/location/proximity-check
     *
     * Permet à l'application mobile client de transmettre sa position actuelle
     * de manière économique pour vérifier l'entrée dans la zone d'un établissement partenaire.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ]);

        /** @var Client $client */
        $client = $request->user();

        // Ignorer les relevés GPS dont l'imprécision est trop forte (> 100 mètres)
        $accuracy = $request->input('accuracy');
        if ($accuracy !== null && (float) $accuracy > 100.0) {
            return response()->json([
                'status' => 'skipped_low_accuracy',
                'message' => 'Position ignorée car la précision GPS est insuffisante.',
            ]);
        }

        $result = $this->proximityService->processClientLocation(
            $client,
            (float) $request->input('latitude'),
            (float) $request->input('longitude')
        );

        return response()->json([
            'status' => 'ok',
            'events' => $result,
        ]);
    }
}
