<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    protected $fillable = [
        'restaurant_id',
        'type',
        'title',
        'message',
        'image_url',
        'kind',
        'trigger_type',
        'target',
        'scheduled_at',
        'sent_at',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * URL publique dynamique de l'image de la campagne, résolue sur l'hôte HTTP courant.
     */
    public function getImageUrlAttribute(?string $value): ?string
    {
        return \App\Support\StorageUrlResolver::resolve($value);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function logs()
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * Nombre de destinataires ciblés par la campagne.
     */
    public function getRecipientsCountAttribute(): int
    {
        $targetCount = (int) ($this->target['recipients_count'] ?? count($this->target['recipient_client_ids'] ?? []));
        if ($targetCount > 0) {
            return $targetCount;
        }

        return $this->relationLoaded('logs') ? $this->logs->count() : $this->logs()->count();
    }

    /**
     * Nombre de notifications transmises avec succès à FCM.
     */
    public function getDeliveredCountAttribute(): int
    {
        if ($this->relationLoaded('logs')) {
            return $this->logs->where('status', 'sent')->count();
        }

        return $this->logs()->where('status', 'sent')->count();
    }

    /**
     * Nombre d'échecs de transmission FCM (aucun token, erreur Firebase, etc.).
     */
    public function getFailedCountAttribute(): int
    {
        if ($this->relationLoaded('logs')) {
            return $this->logs->where('status', 'failed')->count();
        }

        return $this->logs()->where('status', 'failed')->count();
    }

    /**
     * Requête pour récupérer les notifications in-app enregistrées pour cette campagne.
     */
    public function inAppNotifications()
    {
        return Notification::where('type', 'campaign')
            ->where(function ($q) {
                $q->where('data->campaign_id', $this->id)
                  ->orWhere('data->campaign_id', (string) $this->id);
            });
    }

    /**
     * Nombre de clients ayant effectivement lu/ouvert la notification in-app.
     */
    public function getReadCountAttribute(): int
    {
        return $this->inAppNotifications()
            ->whereNotNull('read_at')
            ->count();
    }

    /**
     * Taux de lecture en pourcentage (%) basé sur les notifications délivrées.
     */
    public function getReadRateAttribute(): ?float
    {
        $delivered = $this->delivered_count;
        if ($delivered <= 0) {
            $inAppTotal = $this->inAppNotifications()->count();
            if ($inAppTotal <= 0) {
                return null;
            }
            $delivered = $inAppTotal;
        }

        $read = $this->read_count;

        return round(($read / $delivered) * 100, 1);
    }

    /**
     * Libellé humain du type de campagne.
     */
    public function getReadableTypeAttribute(): string
    {
        return match ($this->type) {
            'promotion' => 'Offre Spéciale',
            'information' => 'Actualité & Événement',
            'reminder' => 'Rappel de Fidélité',
            'reward' => 'Récompense Débloquée',
            default => ucfirst((string) $this->type),
        };
    }

    /**
     * Libellé humain du statut de la campagne.
     */
    public function getReadableStatusAttribute(): string
    {
        return match ($this->status) {
            'sent' => 'Envoyée',
            'scheduled' => 'Programmée',
            'draft' => 'Brouillon',
            'failed' => 'Échouée',
            default => ucfirst((string) $this->status),
        };
    }
}
