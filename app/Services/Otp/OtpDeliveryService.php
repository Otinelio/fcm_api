<?php

namespace App\Services\Otp;

use App\Mail\OtpCodeMail;
use App\Services\Otp\Channels\SmsOtpChannel;
use App\Services\Otp\Channels\WhatsAppOtpChannel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Point d'entrée unique pour l'envoi d'un code OTP — remplace les
 * `Log::info("Code OTP...")` de `ClientAuthController`/`RestaurantAuthController`.
 *
 * Email → Resend (`Mail::`, voir `config/mail.php`). Téléphone → WhatsApp en
 * priorité (`WhatsAppOtpChannel`), SMS en repli (`SmsOtpChannel`) si l'envoi
 * WhatsApp échoue pour n'importe quelle raison — pas de vérification
 * préalable "ce numéro a-t-il WhatsApp", voir la doc de `WhatsAppOtpChannel`.
 *
 * En environnement de test (`APP_ENV=testing`, toujours vrai sous
 * `php artisan test`) ou quand `APP_DEBUG` est actif, aucun appel réseau
 * réel n'est fait — le code reste visible via `debug_otp` dans la réponse
 * des contrôleurs. Développement local et suite de tests ne dépensent donc
 * jamais un SMS/WhatsApp réel, et n'ont besoin d'aucun `Http::fake()`.
 */
class OtpDeliveryService
{
    public function __construct(
        private readonly WhatsAppOtpChannel $whatsapp,
        private readonly SmsOtpChannel $sms,
    ) {
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

        if ($this->whatsapp->send($identifier, $code)) {
            return;
        }

        $this->sms->send($identifier, $code);
    }

    /** `protected`, pas `private` : les tests le surchargent (sous-classe anonyme) pour exercer les envois réels sans dépendre de la détection d'environnement. */
    protected function shouldSkipRealDelivery(): bool
    {
        return App::environment('testing') || (bool) config('app.debug');
    }

    private function sendEmail(string $email, string $code): void
    {
        try {
            Mail::to($email)->send(new OtpCodeMail($code));
        } catch (Throwable $e) {
            // Ne jamais faire échouer la requête HTTP à cause d'une panne du
            // fournisseur mail : le code reste valide en cache le temps
            // configuré, l'utilisateur peut redemander l'envoi.
            Log::error("OtpDeliveryService: échec envoi email OTP à {$email}.", [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
