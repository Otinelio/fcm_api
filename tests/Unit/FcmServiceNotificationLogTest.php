<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Régression pour un bug réel observé en usage : `FcmService::sendToToken()`
 * écrivait dans `notification_logs` avec des colonnes (`user_id`, `type`,
 * `title`, `body`) qui n'existent pas dans le schéma réel de la table
 * (`client_id`, `channel`, `status`, `failure_reason`). Le push FCM partait
 * bien, mais l'écriture du log levait une `PDOException` — le job
 * `SendPromoNotification` (utilisé par le parrainage ET les anniversaires)
 * échouait donc APRÈS l'envoi réel, et le worker de queue le retentait,
 * renvoyant le push à chaque tentative : plusieurs notifications réelles sur
 * l'appareil pour un seul événement, avant l'abandon final en `failed_jobs`.
 *
 * Ce test n'appelle pas FcmService (nécessiterait de vraies identifiants
 * Firebase) : il verrouille directement le contrat — les colonnes que
 * `sendToToken()` écrit doivent exister et accepter ces valeurs.
 */
class FcmServiceNotificationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_log_accepts_the_columns_fcm_service_writes(): void
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000099',
            'password' => bcrypt('secret123'),
        ]);

        // Exactement l'insert de FcmService::sendToToken() après un envoi réussi.
        $log = NotificationLog::create([
            'client_id' => $client->id,
            'channel' => 'fcm',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'id' => $log->id,
            'client_id' => $client->id,
            'channel' => 'fcm',
            'status' => 'sent',
        ]);
    }
}
