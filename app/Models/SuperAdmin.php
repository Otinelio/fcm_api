<?php

namespace App\Models;

use App\Support\AvatarHelper;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class SuperAdmin extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory, Notifiable;

    protected $table = 'super_admins';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $attributes = [
        'role' => 'admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Contrôle l'accès au panneau Filament.
     *
     * Seuls les comptes dont le rôle est explicitement `super_admin` ou
     * `admin` peuvent accéder au back-office.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $role = $this->role ?: 'admin';

        return in_array($role, ['super_admin', 'admin'], true);
    }

    /** Vérifie si le compte a le privilège super-administrateur complet. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** Vérifie si le compte est un administrateur standard (droits restreints). */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Avatar local dynamique avec initiales pour la barre supérieure.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return AvatarHelper::forUser($this->name);
    }
}
