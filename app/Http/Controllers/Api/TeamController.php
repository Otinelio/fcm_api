<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    /**
     * GET /api/auth/merchant/team
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $team = $restaurant->staffUsers()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'created_at'])
            ->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'email'      => $s->email,
                'phone'      => $s->phone,
                'role'       => $s->role,
                'is_active'  => $s->is_active,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return response()->json(['team' => $team]);
    }

    /**
     * POST /api/auth/merchant/team
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $request->validate([
            'name'     => ['required', 'string', 'max:150'],
            'email'    => ['required', 'email', Rule::unique('staff_users', 'email')],
            'phone'    => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', Rule::in(['admin', 'operator'])],
        ], [
            'email.unique' => 'Cette adresse est déjà utilisée par un membre de l\'équipe.',
        ]);

        $staff = $restaurant->staffUsers()->create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => $request->password,
            'role'     => $request->role,
        ]);

        return response()->json([
            'message' => 'Membre de l\'équipe ajouté.',
            'staff'   => [
                'id' => $staff->id, 'name' => $staff->name, 'email' => $staff->email,
                'phone' => $staff->phone, 'role' => $staff->role, 'is_active' => $staff->is_active,
            ],
        ], 201);
    }

    /**
     * PUT /api/auth/merchant/team/{staffUser}
     */
    public function update(Request $request, StaffUser $staffUser): JsonResponse
    {
        $restaurant = $this->authorizeStaff($request, $staffUser);

        $request->validate([
            'name'     => ['sometimes', 'string', 'max:150'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:30'],
            'role'     => ['sometimes', Rule::in(['admin', 'operator'])],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        $data = $request->only(['name', 'phone', 'role']);
        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $staffUser->update($data);

        if ($request->filled('password')) {
            // Un mot de passe réinitialisé (ex : compromission suspectée)
            // doit invalider tout token déjà émis pour ce membre, sinon
            // l'ancien token reste utilisable malgré le changement.
            $this->revokeStaffTokens($restaurant, $staffUser);
        }

        return response()->json(['message' => 'Membre mis à jour.']);
    }

    /**
     * PATCH /api/auth/merchant/team/{staffUser}/toggle-active
     */
    public function toggleActive(Request $request, StaffUser $staffUser): JsonResponse
    {
        $restaurant = $this->authorizeStaff($request, $staffUser);

        $request->validate(['is_active' => ['required', 'boolean']]);

        $staffUser->update(['is_active' => $request->boolean('is_active')]);

        if (! $staffUser->is_active) {
            $this->revokeStaffTokens($restaurant, $staffUser);
        }

        return response()->json([
            'message' => $staffUser->is_active ? 'Membre réactivé.' : 'Membre désactivé.',
        ]);
    }

    private function authorizeStaff(Request $request, StaffUser $staffUser): Restaurant
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        abort_if($staffUser->restaurant_id !== $restaurant->id, 404);

        return $restaurant;
    }

    /**
     * Les tokens appartiennent au Restaurant (voir CurrentActor) : on
     * révoque uniquement ceux portant l'ability de ce membre précis, pas
     * tous les tokens du restaurant.
     */
    private function revokeStaffTokens(Restaurant $restaurant, StaffUser $staffUser): void
    {
        $restaurant->tokens()
            ->get()
            ->filter(fn ($token) => in_array("staff:{$staffUser->id}", $token->abilities ?? [], true))
            ->each(fn ($token) => $token->delete());
    }
}
