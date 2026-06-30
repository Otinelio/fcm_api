<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Services\Fcm\FcmService;

class SendPromoNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $userId,
        private string $token,
        private array $notification
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FcmService $fcm): void
    {
        $fcm->sendToToken($this->token, $this->notification, ['type' => 'promo'], $this->userId, 'promo');
    }
}
