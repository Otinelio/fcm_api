<?php
namespace App\Services\Fcm;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\NotificationLog;

class FcmService
{
    public function getAccessToken(): string
    {
        // On met le token en cache pour ne pas en régénérer un à chaque envoi
        // (il est valide ~1h, FCM/Google rate-limite la génération de tokens)
        return Cache::remember('fcm_access_token', 3500, function () {
            $client = new GoogleClient();
            $client->setAuthConfig(storage_path('app/firebase/service-account.json'));
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

            $token = $client->fetchAccessTokenWithAssertion();
            return $token['access_token'];
        });
    }

    public function sendToToken(string $deviceToken, array $notification, array $data = [], ?int $userId = null, string $type = 'promo'): bool
    {
        $projectId = "restau-loyalty";

        // S'assurer que le type est bien présent dans la payload data pour le routage Flutter
        if (!isset($data['type'])) {
            $data['type'] = $type;
        }

        $response = Http::withToken($this->getAccessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => array_filter([
                    'token' => $deviceToken,
                    'notification' => $notification,
                    'data' => $data,

                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel'
                        ]
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default'
                            ]
                        ]
                    ]
                ]),
            ]);

        if ($response->successful()) {
            // Enregistrer dans l'historique si un userId est fourni
            if ($userId) {
                NotificationLog::create([
                    'user_id' => $userId,
                    'type' => $type,
                    'title' => $notification['title'] ?? '',
                    'body' => $notification['body'] ?? '',
                    'sent_at' => now(),
                ]);
            }
            return true;
        }

        // Token mort : on le supprime pour ne plus jamais réessayer
        if ($response->status() === 404 || str_contains($response->body(), 'UNREGISTERED')) {
            \App\Models\DeviceToken::where('token', $deviceToken)->delete();
        }

        Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->body()]);
        return false;
    }

    public function sendToTopic(string $topic, array $notification, array $data = []): bool
    {
        $projectId = "restau-loyalty";

        $response = Http::withToken($this->getAccessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => array_filter([
                    'topic' => $topic,
                    'notification' => $notification,
                    'data' => $data,

                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel'
                        ]
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default'
                            ]
                        ]
                    ]
                ]),
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::warning("FCM topic send failed to topic {$topic}", ['status' => $response->status(), 'body' => $response->body()]);
        return false;
    }
}