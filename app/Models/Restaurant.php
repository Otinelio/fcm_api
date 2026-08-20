<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

/**
 * @property Point|null $location
 */
class Restaurant extends Authenticatable
{
    use HasApiTokens, HasFactory, HasSpatial, Notifiable;

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
        'country',
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
        'location',
        'sms_credits',
        'short_code',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'location' => Point::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant) {
            $restaurant->uuid ??= (string) Str::uuid();
            $restaurant->qr_token ??= (string) Str::uuid();
            $restaurant->short_code ??= self::generateShortCode();
            $restaurant->status ??= 'active';
            // Renseigné ici plutôt que de compter sur le défaut SQL : sinon
            // l'instance renvoyée juste après l'inscription n'a pas encore
            // l'attribut et le dashboard afficherait 0 crédit.
            $restaurant->sms_credits ??= 100;
        });
    }

    /**
     * Code court tapable à la main (8 caractères) — contrepartie de
     * `qr_token` (UUID), destiné au scan caméra, illisible/imprononçable
     * pour une saisie manuelle.
     */
    private static function generateShortCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (self::where('short_code', $code)->exists());

        return $code;
    }

    /**
     * Vérifie si les informations business minimales (step1) ont été renseignées.
     */
    public function hasBusinessInfo(): bool
    {
        return ! empty($this->name) && ! empty($this->category);
    }

    /**
     * Vérifie si le restaurant a été positionné sur la carte (étape
     * localisation, après step1).
     */
    public function hasLocation(): bool
    {
        return ! is_null($this->location);
    }

    public function loyaltyProgram()
    {
        return $this->hasOne(LoyaltyProgram::class);
    }

    /**
     * Vérifie si le programme de fidélité (step2/step3, config carte) a été
     * créé — signal d'onboarding réellement terminé, contrairement à
     * [hasBusinessInfo] qui ne couvre que step1.
     */
    public function hasLoyaltyProgram(): bool
    {
        return ! is_null($this->loyaltyProgram);
    }

    /**
     * Formule d'abonnement (table `plans`). `free` tant qu'aucun plan n'est
     * rattaché — c'est ce slug que le dashboard marchand affiche.
     */
    public function planSlug(): string
    {
        if (! $this->plan_id) {
            return 'free';
        }

        return DB::table('plans')->where('id', $this->plan_id)->value('slug') ?? 'free';
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
