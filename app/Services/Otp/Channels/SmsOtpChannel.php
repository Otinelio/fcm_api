<?php

namespace App\Services\Otp\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi d'un code OTP par SMS via Africa's Talking — canal de repli quand
 * `WhatsAppOtpChannel::send()` échoue. Couverture pensée pour l'Afrique de
 * l'Ouest (Togo, Bénin, Côte d'Ivoire, Ghana, Nigeria...), contrairement à
 * Twilio dont la couverture régionale est inégale sur ce marché.
 */
class SmsOtpChannel
{
    public function send(string $phoneE164, string $code): bool
    {
        $username = config('services.africastalking.username');
        $apiKey = config('services.africastalking.api_key');
        if (! $username || ! $apiKey) {
            Log::error("SmsOtpChannel: identifiants Africa's Talking manquants, envoi impossible vers {$phoneE164}.");

            return false;
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                ->timeout(8)
                ->post('https://api.africastalking.com/version1/messaging', [
                    'username' => $username,
                    'to' => $phoneE164,
                    'message' => "Miva Fid - votre code de verification : {$code}",
                    'from' => config('services.africastalking.sender_id'),
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::error("SmsOtpChannel: échec envoi vers {$phoneE164}.", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
