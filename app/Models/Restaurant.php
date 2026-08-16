<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Restaurant extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'restaurants';

    protected $fillable = [
        'uuid',
        'name',
        'category',
        'email',
        'phone',
        'password',
        'address',
        'city',
        'description',
        'logo_url',
        'whatsapp',
        'instagram',
        'facebook',
        'tiktok',
        'status',
        'qr_token',
        'plan_id',
        'oauth_provider',
        'oauth_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant) {
            $restaurant->uuid ??= (string) Str::uuid();
            $restaurant->qr_token ??= (string) Str::uuid();
            $restaurant->status ??= 'active';
        });
    }

    /**
     * Vérifie si les informations business minimales (step1) ont été renseignées.
     */
    public function hasBusinessInfo(): bool
    {
        return ! empty($this->name) && ! empty($this->category);
    }

    public function loyaltyProgram()
    {
        return $this->hasOne(LoyaltyProgram::class);
    }

    /** Vrai si ce compte s'est inscrit via OAuth (pas de mot de passe). */
    public function isOAuthUser(): bool
    {
        return ! empty($this->oauth_provider);
    }

    /**
     * Message affiché quand une action est refusée parce que ce compte
     * utilise une autre méthode d'authentification (mirror `Client`).
     */
    public function authMethodDeniedMessage(): string
    {
        if ($this->isOAuthUser()) {
            $provider = ucfirst((string) $this->oauth_provider);

            return "Ce compte utilise une connexion {$provider}. Connectez-vous avec {$provider} pour accéder à votre compte.";
        }

        return 'Un compte existe déjà avec cet e-mail et utilise un mot de passe. Connectez-vous avec votre mot de passe.';
    }
}
