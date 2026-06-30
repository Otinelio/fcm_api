<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Jobs\SendPromoNotification;

class SendGlobalNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-all {title?} {body?} {--delay=5 : Délai en minutes avant l\'envoi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie une notification à tous les utilisateurs avec un délai (par défaut 5 minutes)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $title = $this->argument('title') ?? 'Annonce Spéciale 🚀';
        $body = $this->argument('body') ?? 'Découvrez nos nouveautés dès maintenant !';
        $delay = (int) $this->option('delay');

        $users = User::with('deviceTokens')->get();
        $count = 0;

        $delayTime = now()->addMinutes($delay);

        foreach ($users as $user) {
            foreach ($user->deviceTokens as $deviceToken) {
                if ($delay > 0) {
                    SendPromoNotification::dispatch($user->id, $deviceToken->token, [
                        'title' => $title,
                        'body'  => $body,
                    ])->delay($delayTime);
                } else {
                    SendPromoNotification::dispatch($user->id, $deviceToken->token, [
                        'title' => $title,
                        'body'  => $body,
                    ]);
                }
                $count++;
            }
        }

        $this->info("Job dispatché : {$count} notification(s) prévue(s) pour {$users->count()} utilisateur(s) dans {$delay} minute(s).");
    }
}
