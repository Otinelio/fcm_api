<?php

namespace App\Services\Otp\Channels;

use App\Services\Zavu\ZavuClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi d'un code OTP par SMS via Zavu.dev (avec repli optionnel sur Africa's Talking).
 */
class SmsOtpChannel
{
    public function __construct(
        private readonly ?ZavuClient $zavuClient = null
    ) {}

    private function zavu(): ZavuClient
    {
        return $this->zavuClient ?? app(ZavuClient::class);
    }

    public function send(string $phoneE164, string $code): bool
    {
        $zavu = $this->zavu();

        if ($zavu->isConfigured()) {
            $sent = $zavu->sendSms($phoneE164, "Miva Fid - votre code de verification : {$code}");
            if ($sent) {
                return true;
            }
        }

        // Repli optionnel sur Africa's Talking si Zavu n'est pas configuré ou si l'envoi Zavu a échoué
        $atUsername = config('services.africastalking.username');
        $atApiKey = config('services.africastalking.api_key');

        if (! empty($atUsername) && ! empty($atApiKey)) {
            return $this->sendViaAfricasTalking($atUsername, $atApiKey, $phoneE164, $code);
        }

        Log::error("SmsOtpChannel: aucun identifiant SMS (Zavu / Africa's Talking) configuré ou fonctionnel vers {$phoneE164}.");

        return false;
    }

    /**
     * Repli optionnel sur la REST API Africa's Talking.
     */
    private function sendViaAfricasTalking(string $username, string $apiKey, string $phoneE164, string $code): bool
    {
        try {
            $response = Http::asForm()
                ->withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                ->timeout(8)
                ->post('https://api.africastalking.com/version1/messaging', [
                    'username' => $username,
                    'to'       => $phoneE164,
                    'message'  => "Miva Fid - votre code de verification : {$code}",
                    'from'     => config('services.africastalking.sender_id'),
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error("SmsOtpChannel (Africa's Talking): échec d'envoi vers {$phoneE164}.", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
