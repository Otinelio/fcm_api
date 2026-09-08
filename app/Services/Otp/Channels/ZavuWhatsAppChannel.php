<?php

namespace App\Services\Otp\Channels;

use App\Services\Zavu\ZavuClient;

/**
 * Envoi d'un code OTP par WhatsApp via Zavu.dev.
 */
class ZavuWhatsAppChannel
{
    public function __construct(
        private readonly ?ZavuClient $zavuClient = null
    ) {}

    private function client(): ZavuClient
    {
        return $this->zavuClient ?? app(ZavuClient::class);
    }

    public function send(string $phoneE164, string $code): bool
    {
        $client = $this->client();
        if (! $client->isConfigured()) {
            return false;
        }

        $template = config('services.zavu.whatsapp_template', 'otp_verification');
        $text = "Miva Fid - votre code de verification : {$code}";

        return $client->sendWhatsApp($phoneE164, $text, $template, [$code]);
    }
}
