<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    use HasApiTokens, HasFactory, HasSpatial, Notifiable, SoftDeletes;

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
        'notification_preferences',
        'opening_hours',
        'proximity_settings',
        'social_profiles',
        'fcm_suspended',
        'fcm_suspended_at',
        'fcm_suspension_reason',
    ];

    protected $hidden = [
        'password',
        'oauth_id',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'location' => Point::class,
            'notification_preferences' => 'array',
            'opening_hours' => 'array',
            'proximity_settings' => 'array',
            'social_profiles' => 'array',
            'fcm_suspended' => 'boolean',
            'fcm_suspended_at' => 'datetime',
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

        static::saving(function (Restaurant $restaurant) {
            // Synchronisation intelligente des colonnes directes et social_profiles
            if ($restaurant->isDirty('social_profiles') && is_array($restaurant->social_profiles)) {
                $profiles = $restaurant->social_profiles;
                foreach (['whatsapp', 'instagram', 'facebook', 'tiktok'] as $key) {
                    if (isset($profiles[$key])) {
                        $val = is_array($profiles[$key])
                            ? ($profiles[$key]['handle'] ?? $profiles[$key]['link'] ?? $profiles[$key]['url'] ?? $profiles[$key]['name'] ?? null)
                            : $profiles[$key];
                        if ($val !== null && trim((string) $val) !== '') {
                            $cleanHandle = self::formatSocialHandle($key, (string) $val);
                            if ($cleanHandle) {
                                $restaurant->{$key} = $cleanHandle;
                            }
                        }
                    }
                }
            }
        });
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) ($this->name ?: "Restaurant #{$this->id} (" . ($this->email ?: 'Sans nom') . ")");
    }

    /**
     * URL publique dynamique du logo, résolue sur l'hôte HTTP courant.
     */
    public function getLogoUrlAttribute(?string $value): ?string
    {
        return \App\Support\StorageUrlResolver::resolve($value);
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

    /**
     * Vrai si au moins un jour d'ouverture a été renseigné.
     */
    public function hasOpeningHours(): bool
    {
        return collect($this->opening_hours ?? [])
            ->contains(fn ($day) => is_array($day) && ! empty($day['open']));
    }

    public function loyaltyProgram()
    {
        return $this->hasOne(LoyaltyProgram::class);
    }

    public function staffUsers()
    {
        return $this->hasMany(StaffUser::class);
    }

    public const DEFAULT_PROXIMITY_SETTINGS = [
        'enabled' => false,
        'radius_m' => 500,
        'title' => 'Vous êtes tout près de nous !',
        'message' => 'Passez nous voir et profitez de vos avantages fidélité.',
        'cooldown_hours' => 24,
    ];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function loyaltyCards()
    {
        return $this->hasMany(LoyaltyCard::class);
    }

    public function geoOptins()
    {
        return $this->hasMany(ClientRestaurantGeoOptin::class, 'restaurant_id');
    }

    public function proximitySettings(): array
    {
        return array_merge(
            self::DEFAULT_PROXIMITY_SETTINGS,
            $this->proximity_settings ?? []
        );
    }

    public function isProximityActive(): bool
    {
        $settings = $this->proximitySettings();

        if (! ($settings['enabled'] ?? false)) {
            return false;
        }

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->isFcmSuspended()) {
            return false;
        }

        if (! $this->hasLocation()) {
            return false;
        }

        if ($this->plan_id && $this->plan && ! $this->plan->allows_geolocation) {
            return false;
        }

        return ! empty($settings['title']) && ! empty($settings['message']);
    }

    public function deviceTokens()
    {
        return $this->morphMany(DeviceToken::class, 'tokenable');
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

    /**
     * Nettoie et extrait le handle/pseudo d'un réseau social (sans URL ni @).
     */
    public static function formatSocialHandle(string $platform, ?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $val = trim($input);
        if ($val === '') {
            return null;
        }

        switch (strtolower($platform)) {
            case 'whatsapp':
                $clean = preg_replace('/[^0-9+]/', '', $val);
                return ltrim($clean, '+');
            case 'instagram':
                $val = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $val);
                return trim(ltrim($val, '@/'), '/');
            case 'facebook':
                $val = preg_replace('#^https?://(www\.)?facebook\.com/#i', '', $val);
                return trim(ltrim($val, '@/'), '/');
            case 'tiktok':
                $val = preg_replace('#^https?://(www\.)?tiktok\.com/@?#i', '', $val);
                return trim(ltrim($val, '@/'), '/');
            default:
                return $val;
        }
    }

    /**
     * Construit l'URL externe directe vers le profil du réseau social.
     */
    public static function formatSocialUrl(string $platform, ?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $val = trim($input);
        if ($val === '') {
            return null;
        }

        switch (strtolower($platform)) {
            case 'whatsapp':
                $clean = preg_replace('/[^0-9]/', '', $val);
                return $clean ? "https://wa.me/{$clean}" : null;
            case 'instagram':
                $handle = self::formatSocialHandle('instagram', $val);
                return $handle ? "https://instagram.com/{$handle}" : null;
            case 'facebook':
                $handle = self::formatSocialHandle('facebook', $val);
                return $handle ? "https://facebook.com/{$handle}" : null;
            case 'tiktok':
                $handle = self::formatSocialHandle('tiktok', $val);
                return $handle ? "https://tiktok.com/@{$handle}" : null;
            default:
                if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
                    return $val;
                }
                return "https://{$val}";
        }
    }

    /**
     * Nettoie et extrait le nom d'affichage de la page de l'établissement.
     */
    public static function formatSocialName(string $platform, ?string $input, ?string $fallback = null): ?string
    {
        if ($input !== null && trim($input) !== '') {
            $cleaned = trim($input);
            if (str_contains($cleaned, 'instagram.com/')) {
                $parts = explode('instagram.com/', $cleaned);
                $cleaned = trim(end($parts), '/');
            } elseif (str_contains($cleaned, 'facebook.com/')) {
                $parts = explode('facebook.com/', $cleaned);
                $cleaned = trim(end($parts), '/');
            } elseif (str_contains($cleaned, 'tiktok.com/@')) {
                $parts = explode('tiktok.com/@', $cleaned);
                $cleaned = trim(end($parts), '/');
            }
            $cleaned = ltrim($cleaned, '@');
            if ($cleaned !== '') {
                return $cleaned;
            }
        }

        return $fallback;
    }

    /**
     * Retourne l'URL directe de redirection vers le réseau social spécifié.
     */
    public function getSocialRedirectUrl(string $platform): ?string
    {
        $platform = strtolower($platform);
        $profiles = $this->formattedSocialProfiles();

        if (isset($profiles[$platform]['url']) && ! empty($profiles[$platform]['url'])) {
            return $profiles[$platform]['url'];
        }

        return match ($platform) {
            'whatsapp' => self::formatSocialUrl('whatsapp', $this->whatsapp),
            'instagram' => self::formatSocialUrl('instagram', $this->instagram),
            'facebook' => self::formatSocialUrl('facebook', $this->facebook),
            'tiktok' => self::formatSocialUrl('tiktok', $this->tiktok),
            default => null,
        };
    }

    /**
     * Retourne les profils sociaux formatés avec noms, pseudos, liens directs et lien de redirection.
     */
    // ──────────────────────────────────────────────────────────
    //  Quota / Notifications
    // ──────────────────────────────────────────────────────────

    public function notificationCampaigns()
    {
        return $this->hasMany(NotificationCampaign::class);
    }

    /**
     * Quota initial historique — dans ce modèle, chaque restaurant démarre
     * avec 100 crédits et l'admin peut en ajouter. La valeur « totale »
     * correspond donc au solde actuel + ce qui a déjà été dépensé.
     */
    public function getTotalQuotaAttribute(): int
    {
        return $this->sms_credits + $this->consumed_quota;
    }

    /**
     * Nombre de crédits réellement consommés.
     * Prend en compte :
     * 1. Les campagnes existantes : max(target['recipients_count'], logs_count)
     *    afin de comptabiliser fidèlement les renvois de campagne qui ont généré
     *    des logs supplémentaires sans modifier le target initial.
     * 2. Les logs orphelins des campagnes supprimées (conservés pour audit anti-fraude).
     */
    public function getConsumedQuotaAttribute(): int
    {
        $campaignsConsumption = (int) $this->notificationCampaigns()
            ->where('status', '!=', 'draft')
            ->withCount('logs')
            ->get()
            ->sum(function (NotificationCampaign $c) {
                $target = (int) ($c->target['recipients_count'] ?? 0);
                $logs = (int) ($c->logs_count ?? 0);

                return max($target, $logs);
            });

        $detachedLogs = (int) NotificationLog::where('restaurant_id', $this->id)
            ->whereNull('notification_campaign_id')
            ->count();

        return $campaignsConsumption + $detachedLogs;
    }

    /**
     * Crédits restants = colonne `sms_credits` directement.
     */
    public function getRemainingQuotaAttribute(): int
    {
        return (int) $this->sms_credits;
    }

    /**
     * Pourcentage de quota utilisé (0 – 100).
     */
    public function getQuotaUsagePercentAttribute(): float
    {
        $total = $this->total_quota;
        if ($total <= 0) {
            return 0.0;
        }

        return round(($this->consumed_quota / $total) * 100, 1);
    }

    /**
     * Historique mensuel de consommation (6 derniers mois).
     *
     * @return array<array{month: string, count: int}>
     */
    public function getMonthlyConsumptionAttribute(): array
    {
        $months = collect();
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();

            $campaignsCount = (int) $this->notificationCampaigns()
                ->where('status', '!=', 'draft')
                ->whereBetween('created_at', [$start, $end])
                ->withCount('logs')
                ->get()
                ->sum(function (NotificationCampaign $c) {
                    $target = (int) ($c->target['recipients_count'] ?? 0);
                    $logs = (int) ($c->logs_count ?? 0);

                    return max($target, $logs);
                });

            $detachedCount = (int) NotificationLog::where('restaurant_id', $this->id)
                ->whereNull('notification_campaign_id')
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $months->push([
                'month' => ucfirst($date->translatedFormat('M Y')),
                'count' => $campaignsCount + $detachedCount,
            ]);
        }

        return $months->all();
    }

    /**
     * Statut résumé du service de notification pour cet établissement.
     *
     * @return array{label: string, color: string, icon: string, reason?: string|null, suspended_at?: mixed}
     */
    public function getNotificationServiceStatusAttribute(): array
    {
        if ($this->status !== 'active') {
            return ['label' => 'Compte inactif', 'color' => 'gray', 'icon' => 'heroicon-o-pause-circle'];
        }

        if ($this->isFcmSuspended()) {
            return [
                'label' => 'Suspendu',
                'color' => 'danger',
                'icon' => 'heroicon-o-no-symbol',
                'reason' => $this->fcm_suspension_reason,
                'suspended_at' => $this->fcm_suspended_at,
            ];
        }

        $credits = $this->sms_credits;

        if ($credits <= 0) {
            return ['label' => 'Épuisé', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'];
        }

        if ($credits <= 10) {
            return ['label' => 'Critique', 'color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'];
        }

        return ['label' => 'Actif', 'color' => 'success', 'icon' => 'heroicon-o-check-circle'];
    }

    public function isFcmSuspended(): bool
    {
        return (bool) $this->fcm_suspended;
    }

    public function suspendFcm(?string $reason = null): void
    {
        $this->update([
            'fcm_suspended' => true,
            'fcm_suspended_at' => now(),
            'fcm_suspension_reason' => $reason ? trim($reason) : null,
        ]);
    }

    public function reactivateFcm(): void
    {
        $this->update([
            'fcm_suspended' => false,
            'fcm_suspended_at' => null,
            'fcm_suspension_reason' => null,
        ]);

        // Aligner les campagnes déjà programmées dont l'échéance est passée pendant la suspension
        // pour qu'elles puissent être envoyées au prochain créneau sans doublons ni perte de crédits.
        $throttle = app(\App\Services\Campaigns\CampaignThrottle::class);
        $nextStart = $throttle->isWithinSendWindow() ? now() : $throttle->nextWindowStart();

        $this->notificationCampaigns()
            ->where('status', 'scheduled')
            ->whereNull('archived_at')
            ->where('scheduled_at', '<=', now())
            ->update([
                'scheduled_at' => $nextStart,
            ]);
    }

    public function formattedSocialProfiles(): array
    {
        $raw = $this->social_profiles ?? [];
        $result = [];
        $order = $raw['_order'] ?? [];

        $platforms = ['whatsapp', 'instagram', 'facebook', 'tiktok'];

        foreach ($platforms as $platform) {
            $entry = $raw[$platform] ?? null;
            $rawInput = null;
            $name = null;

            if (is_string($entry)) {
                $rawInput = $entry;
            } elseif (is_array($entry)) {
                $name = $entry['name'] ?? null;
                $rawInput = $entry['handle'] ?? $entry['link'] ?? $entry['url'] ?? $name ?? null;
            }

            if (! $rawInput) {
                $rawInput = match ($platform) {
                    'whatsapp' => $this->whatsapp,
                    'instagram' => $this->instagram,
                    'facebook' => $this->facebook,
                    'tiktok' => $this->tiktok,
                    default => null,
                };
            }

            if ($rawInput && trim((string) $rawInput) !== '') {
                $url = self::formatSocialUrl($platform, (string) $rawInput);
                $handle = self::formatSocialHandle($platform, (string) $rawInput);
                $displayName = self::formatSocialName(
                    $platform,
                    $name ?? (string) $rawInput,
                    $this->name ?? ucfirst($platform)
                );

                $result[$platform] = [
                    'name' => $displayName,
                    'handle' => $handle,
                    'link' => $url,
                    'url' => $url,
                    'redirect_url' => url("/r/{$this->short_code}/{$platform}"),
                ];
            }
        }

        if (! empty($order) && is_array($order)) {
            $result['_order'] = $order;
        }

        return $result;
    }
}

