<?php

namespace App\Services\Campaigns;

use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use Illuminate\Support\Carbon;

/**
 * Garde-fous d'envoi des campagnes pendant la phase de test gratuite : pas
 * de plan/quota payant, juste deux limites fixes pour éviter un envoi
 * massif ou nocturne accidentel pendant les tests.
 *
 * `config('app.timezone')` est `UTC`, qui correspond à l'heure de Lomé
 * (GMT+0, pas de décalage) — `now()` peut donc être comparé directement aux
 * heures d'ouverture sans conversion.
 */
class CampaignThrottle
{
    /** Plage d'envoi autorisée, heure locale (= UTC ici). */
    public const WINDOW_START_HOUR = 8;
    public const WINDOW_END_HOUR = 20;

    /** Destinataires/jour, tous commerces confondus par leur propre compteur. */
    public const DAILY_RECIPIENT_CAP = 200;

    public function isWithinSendWindow(?Carbon $at = null): bool
    {
        $hour = ($at ?? now())->hour;

        return $hour >= self::WINDOW_START_HOUR && $hour < self::WINDOW_END_HOUR;
    }

    /** Prochaine ouverture de la plage — aujourd'hui si on est avant, demain sinon. */
    public function nextWindowStart(?Carbon $from = null): Carbon
    {
        $from = $from ?? now();
        $todayStart = $from->copy()->setTime(self::WINDOW_START_HOUR, 0, 0);

        return $from->hour < self::WINDOW_START_HOUR ? $todayStart : $todayStart->addDay();
    }

    /** Destinataires déjà servis aujourd'hui par ce commerce (campagnes réellement envoyées). */
    public function recipientsSentToday(Restaurant $restaurant): int
    {
        return NotificationCampaign::where('restaurant_id', $restaurant->id)
            ->where('status', 'sent')
            ->whereDate('sent_at', now()->toDateString())
            ->get()
            ->sum(fn (NotificationCampaign $c) => (int) ($c->target['recipients_count'] ?? 0));
    }

    public function remainingToday(Restaurant $restaurant): int
    {
        return max(0, self::DAILY_RECIPIENT_CAP - $this->recipientsSentToday($restaurant));
    }
}
