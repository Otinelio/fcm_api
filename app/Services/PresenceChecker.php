<?php

namespace App\Services;

use Pusher\Pusher;
use Pusher\PusherException;
use Illuminate\Support\Facades\Log;

class PresenceChecker
{
    protected Pusher $pusher;

    public function __construct()
    {
        $this->pusher = new Pusher(
            config('reverb.apps.apps.0.key'),
            config('reverb.apps.apps.0.secret'),
            config('reverb.apps.apps.0.app_id'),
            [
                // Important : on pointe sur l'adresse INTERNE du process Reverb
                // (127.0.0.1:8080), pas sur le domaine public servi par Nginx.
                // Backend et Reverb tournent sur la même machine ici — aucune
                // raison de faire transiter cet appel serveur-à-serveur par
                // le reverse proxy, et ça reste fonctionnel même si Nginx
                // est temporairement indisponible.
                'host' => '127.0.0.1',
                'port' => config('reverb.servers.reverb.port', 8080),
                'scheme' => 'http',
                'useTLS' => false,
            ]
        );
    }

    /**
     * Interroge Reverb (via l'API compatible Pusher) pour savoir
     * si un client a un socket actif sur son presence channel personnel.
     */
    public function isCustomerOnline(int $customerId): bool
    {
        $channelName = "presence-customer.{$customerId}";

        try {
            $response = $this->pusher->get("/channels/{$channelName}/users");
            
            // Le SDK Pusher (version 7+) retourne un objet stdClass déjà décodé
            return ! empty($response->users);
        } catch (PusherException $e) {
            // Si l'API Reverb est inaccessible, on considère le client absent
            // par sécurité : ça déclenchera le fallback FCM, ce qui est le
            // comportement le moins risqué (mieux vaut un push en trop
            // qu'un client jamais notifié).
            Log::warning('PresenceChecker: échec de vérification', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
