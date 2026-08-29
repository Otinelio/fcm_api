<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Restaurant;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

class SendGlobalNotification extends Command
{
    protected $signature = 'notifications:send-all {title?} {body?} {--delay=5 : Délai en minutes avant l\'envoi}';

    protected $description = 'Envoie une notification à tous les clients et marchands avec un délai (par défaut 5 minutes)';

    public function handle(NotificationDispatcher $notifications): void
    {
        $title = $this->argument('title') ?? 'Annonce Spéciale 🚀';
        $body = $this->argument('body') ?? 'Découvrez nos nouveautés dès maintenant !';
        $delay = (int) $this->option('delay');

        $recipients = Client::with('deviceTokens')->get()
            ->concat(Restaurant::with('deviceTokens')->get());

        $count = 0;

        foreach ($recipients as $recipient) {
            if ($delay > 0) {
                dispatch(function () use ($notifications, $recipient, $title, $body) {
                    $notifications->send($recipient, 'admin_broadcast', $title, $body);
                })->delay(now()->addMinutes($delay));
            } else {
                $notifications->send($recipient, 'admin_broadcast', $title, $body);
            }
            $count++;
        }

        $this->info("Notification prévue pour {$count} destinataire(s) dans {$delay} minute(s).");
    }
}
