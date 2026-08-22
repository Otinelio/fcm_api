<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffUser;
use App\Support\RestaurantPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    /**
     * POST /api/auth/merchant/staff/login
     *
     * Le token émis appartient au `Restaurant` parent (pas au `StaffUser`) :
     * voir CurrentActor / docs/superpowers/specs/2026-08-21-equipe-roles-admin-operateur-design.md.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $staffUser = StaffUser::where('email', $request->email)->first();

        if (! $staffUser || ! Hash::check($request->password, $staffUser->password)) {
            return response()->json([
                'message' => 'Identifiants incorrects.',
            ], 401);
        }

        if (! $staffUser->is_active) {
            return response()->json([
                'message' => 'Ce compte a été désactivé. Contactez votre administrateur.',
            ], 401);
        }

        $restaurant = $staffUser->restaurant;
        $token = $restaurant->createToken(
            "staff:{$staffUser->id}",
            ["staff:{$staffUser->id}"],
        )->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'restaurant'   => RestaurantPayload::build($restaurant),
            'actor'        => [
                'type' => 'staff',
                'id'   => $staffUser->id,
                'name' => $staffUser->name,
                'role' => $staffUser->role,
            ],
        ]);
    }
}
