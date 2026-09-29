<?php

namespace App\Jobs;

use App\Services\Fcm\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $token,
        public array $notification,
        public array $data = [],
        public ?int $clientId = null,
        public ?string $type = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FcmService $fcm): void
    {
        $fcm->sendToToken(
            $this->token,
            $this->notification,
            $this->data,
            $this->clientId,
            $this->type,
        );
    }
}
