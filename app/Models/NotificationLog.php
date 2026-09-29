<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(NotificationCampaign::class, 'notification_campaign_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Explication humaine de la raison de l'échec.
     */
    public function getReadableFailureReasonAttribute(): ?string
    {
        if (! $this->failure_reason) {
            return null;
        }

        return match ($this->failure_reason) {
            'no_device_token' => 'Aucun appareil enregistré (application non installée ou notifications refusées)',
            'fcm_send_failed' => 'Échec d\'envoi auprès des serveurs Firebase (token expiré ou erreur réseau)',
            default => str_starts_with((string) $this->failure_reason, 'exception:')
                ? 'Erreur technique lors de la transmission'
                : $this->failure_reason,
        };
    }

    /**
     * Recherche la notification in-app associée à ce client pour cette campagne.
     */
    public function getInAppNotificationAttribute(): ?Notification
    {
        return Notification::where('notifiable_type', Client::class)
            ->where('notifiable_id', $this->client_id)
            ->where('type', 'campaign')
            ->where(function ($q) {
                $q->where('data->campaign_id', $this->notification_campaign_id)
                  ->orWhere('data->campaign_id', (string) $this->notification_campaign_id);
            })
            ->latest()
            ->first();
    }

    /**
     * Indique si le client a ouvert/lu la notification dans l'application.
     */
    public function getIsReadAttribute(): bool
    {
        return $this->in_app_notification?->read_at !== null;
    }

    /**
     * Date de lecture dans l'application si lue.
     */
    public function getReadAtAttribute()
    {
        return $this->in_app_notification?->read_at;
    }
}
