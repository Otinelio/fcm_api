<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use Illuminate\Http\JsonResponse;

class ClientAdvertisementController extends Controller
{
    /**
     * GET /api/client/advertisements
     *
     * Retourne uniquement les publicités actives dont la période d'affichage
     * couvre l'instant présent.
     */
    public function index(): JsonResponse
    {
        $advertisements = Advertisement::query()
            ->active()
            ->orderBy('order', 'asc')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'advertisements' => $advertisements,
        ]);
    }
}
