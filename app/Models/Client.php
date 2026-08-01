<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'clients';

    protected $fillable = [
        'uuid',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'birthdate',
        'city',
        'country',
        'avatar_url',
        'referral_code',
        'referred_by_client_id',
        'fcm_token',
        'oauth_provider',
        'oauth_id',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'oauth_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'birthdate'         => 'date',
            'phone_verified_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Vérifie si le profil est complet (a un phone et un first_name).
     */
    public function isProfileComplete(): bool
    {
        return ! empty($this->phone) && ! empty($this->first_name);
    }

    /**
     * Vérifie si le client s'est inscrit via OAuth.
     */
    public function isOAuthUser(): bool
    {
        return ! empty($this->oauth_provider);
    }

    /**
     * Nom complet du client.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    // ─────────────────────────────────────────────────────────
    // Relations
    // ─────────────────────────────────────────────────────────

    public function loyaltyCards()
    {
        return $this->hasMany(LoyaltyCard::class);
    }

    public function geoOptins()
    {
        return $this->hasMany(\App\Models\ClientRestaurantGeoOptin::class ?? null, 'client_id');
    }

    public function referrer()
    {
        return $this->belongsTo(self::class, 'referred_by_client_id');
    }

    public function referrals()
    {
        return $this->hasMany(self::class, 'referred_by_client_id');
    }
}
