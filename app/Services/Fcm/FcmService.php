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
            $credentials = config('services.firebase.credentials');

            $this->assertCredentialsMatchProject($credentials);

            $client = new GoogleClient();
            $client->setAuthConfig($credentials);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

            $token = $client->fetchAccessTokenWithAssertion();
            return $token['access_token'];
        });
    }

    /**
     * Le compte de service et l'endpoint FCM doivent viser le même projet.
     *
     * Sans ce contrôle, un compte de service appartenant à un autre projet
     * produit un jeton parfaitement valide, et l'échec n'apparaît qu'à l'envoi
     * sous la forme d'un « FCM send failed » avec un 403 sans explication.
     */
    private function assertCredentialsMatchProject(string $credentials): void
    {
        if (! is_file($credentials)) {
            throw new \RuntimeException(
                "Compte de service Firebase introuvable : {$credentials}. "
                . 'Le générer depuis Paramètres du projet > Comptes de service.'
            );
        }

        $accountProject = json_decode((string) file_get_contents($credentials), true)['project_id'] ?? null;
        $expectedProject = config('services.firebase.project_id');

        if ($accountProject !== null && $expectedProject && $accountProject !== $expectedProject) {
            throw new \RuntimeException(
                "Le compte de service appartient au projet « {$accountProject} » alors que "
                . "FIREBASE_PROJECT_ID vaut « {$expectedProject} ». Générer une nouvelle clé "
                . "privée depuis le projet « {$expectedProject} » et remplacer {$credentials}."
            );
        }
    }

    public function sendToToken(string $deviceToken, array $notification, array $data = [], ?int $userId = null, string $type = 'promo'): bool
    {
        $projectId = config('services.firebase.project_id');

        // S'assurer que le type est bien présent dans la payload data pour le routage Flutter
        if (!isset($data['type'])) {
            $data['type'] = $type;
        }

        $message = [
            'token' => $deviceToken,
            'data' => $data,
        ];

        if (!empty($notification)) {
            $message['notification'] = $notification;
            $message['android'] = [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => 'high_importance_channel'
                ]
            ];
            $message['apns'] = [
                'payload' => [
                    'aps' => [
                        'sound' => 'default'
                    ]
                ]
            ];
        }

        $response = Http::withToken($this->getAccessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
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
        $projectId = config('services.firebase.project_id');

        $message = [
            'topic' => $topic,
            'data' => $data,
        ];

        if (!empty($notification)) {
            $message['notification'] = $notification;
            $message['android'] = [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => 'high_importance_channel'
                ]
            ];
            $message['apns'] = [
                'payload' => [
                    'aps' => [
                        'sound' => 'default'
                    ]
                ]
            ];
        }

        $response = Http::withToken($this->getAccessToken())
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::warning("FCM topic send failed to topic {$topic}", ['status' => $response->status(), 'body' => $response->body()]);
        return false;
    }
}