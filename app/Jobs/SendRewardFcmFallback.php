<?php

namespace App\Jobs;

use App\Models\Reward;
use App\Models\RewardNotificationLog;
use App\Services\Fcm\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendRewardFcmFallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(public int $rewardId)
    {
    }

    public function handle(FcmService $fcmService): void
    {
        // Barrière n°1 : un ack est arrivé entre temps, pas besoin de FCM
        if (Cache::has("reward_acked:{$this->rewardId}")) {
            Log::info('Fallback FCM annulé : ack déjà reçu', [
                'reward_id' => $this->rewardId,
            ]);
            return;
        }

        // Barrière n°2 : Verrou d'idempotence ATOMIQUE
        // Cache::add() ne réussit que si la clé n'existait pas encore.
        // Si deux exécutions du job arrivent en même temps (retry concurrent), une seule passera ce verrou.
        $lockAcquired = Cache::add(
            "reward_fcm_lock:{$this->rewardId}",
            true,
            now()->addMinutes(5)
        );

        if (! $lockAcquired) {
            Log::info('Fallback FCM annulé : verrou déjà pris (envoi déjà en cours/fait)', [
                'reward_id' => $this->rewardId,
            ]);
            return;
        }

        $reward = Reward::with('user.deviceTokens')->find($this->rewardId);

        if (! $reward) {
            Log::warning('Fallback FCM : reward introuvable', [
                'reward_id' => $this->rewardId,
            ]);
            return;
        }

        $user = $reward->user;
        if (!$user) {
            Log::warning('Fallback FCM : user introuvable pour reward', ['reward_id' => $this->rewardId]);
            return;
        }

        $tokens = $user->deviceTokens;
        Log::info('Fallback FCM : tentative d\'envoi', [
            'reward_id' => $this->rewardId,
            'user_id' => $user->id,
            'tokens_count' => $tokens->count(),
        ]);

        $sent = false;
        foreach ($tokens as $deviceToken) {
            Log::info('Fallback FCM : envoi vers token', [
                'reward_id' => $this->rewardId,
                'token_prefix' => substr($deviceToken->token, 0, 20),
                'platform' => $deviceToken->platform,
            ]);

            $success = $fcmService->sendToToken(
                $deviceToken->token,
                ['title' => $reward->title, 'body' => $reward->description],
                [
                    'type' => 'reward_unlocked',
                    'reward_id' => (string) $reward->id,
                ],
                $user->id,
                'reward'
            );

            Log::info('Fallback FCM : résultat sendToToken', [
                'reward_id' => $this->rewardId,
                'success' => $success,
            ]);

            if ($success) $sent = true;
        }

        if ($sent) {
            RewardNotificationLog::create([
                'reward_id' => $this->rewardId,
                'channel' => 'fcm',
                'status' => 'sent',
            ]);
            Log::info('Fallback FCM envoyé avec succès', ['reward_id' => $this->rewardId]);
        } else {
            RewardNotificationLog::create([
                'reward_id' => $this->rewardId,
                'channel' => 'fcm',
                'status' => 'failed',
            ]);
            Log::warning('Fallback FCM : aucun envoi réussi', ['reward_id' => $this->rewardId]);
        }
    }
}
