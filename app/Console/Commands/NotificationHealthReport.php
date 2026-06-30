<?php

namespace App\Console\Commands;

use App\Models\RewardNotificationLog;
use Illuminate\Console\Command;

class NotificationHealthReport extends Command
{
    protected $signature = 'notifications:health-report';
    protected $description = 'Affiche le taux de fallback FCM sur les dernières 24h';

    public function handle(): void
    {
        $since = now()->subDay();

        $reverbCount = RewardNotificationLog::where('channel', 'reverb')
            ->where('created_at', '>=', $since)
            ->count();

        $fcmCount = RewardNotificationLog::where('channel', 'fcm')
            ->where('created_at', '>=', $since)
            ->count();

        $rate = $reverbCount > 0 ? round(($fcmCount / $reverbCount) * 100, 1) : 0;

        $this->info("Diffusions Reverb : {$reverbCount}");
        $this->info("Fallbacks FCM : {$fcmCount}");
        $this->info("Taux de fallback : {$rate}%");

        if ($rate > 50) {
            $this->warn('⚠ Taux de fallback anormalement élevé — vérifier la santé de Reverb.');
        }
    }
}
