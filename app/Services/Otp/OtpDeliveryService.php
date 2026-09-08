<?php

namespace App\Services\Otp;

use App\Mail\OtpCodeMail;
use App\Services\Otp\Channels\SmsOtpChannel;
use App\Services\Otp\Channels\WhatsAppOtpChannel;
use App\Services\Otp\Channels\ZavuEmailChannel;
use App\Services\Otp\Channels\ZavuWhatsAppChannel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Point d'entrée unique pour l'envoi d'un code OTP.
 *
 * Pour les numéros de téléphone, envoi direct par SMS via Zavu.dev (SmsOtpChannel)
 * avec repli sur Africa's Talking si besoin.
 */
class OtpDeliveryService
{
    public function __construct(
        private readonly WhatsAppOtpChannel $whatsapp,
        private readonly SmsOtpChannel $sms,
        private readonly ?ZavuWhatsAppChannel $zavuWhatsapp = null,
        private readonly ?ZavuEmailChannel $zavuEmail = null,
    ) {
    }

    private function zavuMail(): ZavuEmailChannel
    {
        return $this->zavuEmail ?? app(ZavuEmailChannel::class);
    }

    public function send(string $identifier, string $code): void
    {
        if ($this->shouldSkipRealDelivery()) {
            return;
        }

        if (str_contains($identifier, '@')) {
            $this->sendEmail($identifier, $code);

            return;
        }

        // Envoi SMS direct via Zavu.dev (avec fallback Africa's Talking)
        $this->sms->send($identifier, $code);
    }

    /** `protected` : surchargé dans les tests unitaires ou contourné si ZAVU_FORCE_DELIVERY=true en dev local. */
    protected function shouldSkipRealDelivery(): bool
    {
        if (App::environment('testing')) {
            return true;
        }

        if (config('services.zavu.force_delivery')) {
            return false;
        }

        return (bool) config('app.debug');
    }

    private function sendEmail(string $email, string $code): void
    {
        // Tenter l'envoi Email via Zavu.dev
        if ($this->zavuMail()->send($email, $code)) {
            return;
        }

        // Repli sur Laravel Mail (Resend / SMTP)
        try {
            Mail::to($email)->send(new OtpCodeMail($code));
        } catch (Throwable $e) {
            Log::error("OtpDeliveryService: échec envoi email OTP à {$email}.", [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
