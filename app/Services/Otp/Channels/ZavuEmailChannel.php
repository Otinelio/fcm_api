<?php

namespace App\Services\Otp\Channels;

use App\Services\Zavu\ZavuClient;

/**
 * Envoi d'un code OTP par Email via Zavu.dev.
 */
class ZavuEmailChannel
{
    public function __construct(
        private readonly ?ZavuClient $zavuClient = null
    ) {}

    private function client(): ZavuClient
    {
        return $this->zavuClient ?? app(ZavuClient::class);
    }

    public function send(string $email, string $code): bool
    {
        $client = $this->client();
        if (! $client->isConfigured()) {
            return false;
        }

        $subject = 'Miva-Fid — Votre code de vérification';
        $htmlBody = "
            <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                <h2>Code de vérification Miva-Fid</h2>
                <p>Voici votre code pour réinitialiser votre mot de passe :</p>
                <div style='font-size: 24px; font-weight: bold; letter-spacing: 4px; color: #E53935; padding: 10px 0;'>
                    {$code}
                </div>
                <p>Ce code expire dans 10 minutes. Si vous n'avez pas demandé ce code, vous pouvez ignorer cet e-mail.</p>
            </div>
        ";

        return $client->sendEmail($email, $subject, $htmlBody);
    }
}
