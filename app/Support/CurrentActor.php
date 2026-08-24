<?php

namespace App\Support;

use App\Models\StaffUser;
use Illuminate\Http\Request;

final class CurrentActor
{
    private function __construct(
        public readonly string $type,
        public readonly ?StaffUser $staffUser,
        public readonly string $role,
    ) {
    }

    public static function resolve(Request $request): self
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $abilities = $token?->abilities ?? [];

        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'staff:')) {
                $staffId = (int) substr($ability, strlen('staff:'));
                $staffUser = StaffUser::find($staffId);

                // Invariant central du modèle : le token appartient au
                // Restaurant ($user ici), pas au StaffUser lui-même. On
                // vérifie donc explicitement que le membre trouvé appartient
                // bien à ce Restaurant, et pas seulement qu'il existe et est
                // actif — défensif, l'ability "staff:{id}" n'est aujourd'hui
                // émise que par StaffAuthController, toujours correctement
                // scopée.
                if ($staffUser === null || ! $staffUser->is_active || $staffUser->restaurant_id !== $user->id) {
                    throw new StaffUserInactiveException();
                }

                return new self('staff', $staffUser, $staffUser->role);
            }
        }

        return new self('restaurant', null, 'admin');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id'   => $this->staffUser?->id,
            'name' => $this->staffUser?->name,
            'role' => $this->role,
        ];
    }
}
