<?php

namespace App\Services\Zavu;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client HTTP centralisé pour la plateforme unifiée Zavu.dev (SMS, WhatsApp, Email).
 * Endpoint principal : POST https://api.zavu.dev/v1/messages
 */
class ZavuClient
{
    private string $baseUrl = 'https://api.zavu.dev/v1';

    /**
     * Vérifie si Zavu est configuré avec une clé d'API valide.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.zavu.api_key'));
    }

    /**
     * Envoie un SMS via Zavu.dev (`channel: "sms"`).
     */
    public function sendSms(string $to, string $text): bool
    {
        return $this->sendMessage([
            'to'      => $to,
            'text'    => $text,
            'channel' => 'sms',
        ]);
    }

    /**
     * Envoie un message WhatsApp via Zavu.dev (`channel: "whatsapp"`).
     */
    public function sendWhatsApp(string $to, string $text, ?string $templateName = null, array $templateParams = []): bool
    {
        $payload = [
            'to'      => $to,
            'text'    => $text,
            'channel' => 'whatsapp',
        ];

        // Seuls les vrais noms de templates (ex: otp_verification) sont inclus dans l'objet template.
        // Si la variable contient un numéro de téléphone ou une chaîne invalide, on envoie le texte direct.
        if ($templateName && ! str_contains($templateName, '+') && ! str_contains($templateName, ' ') && strlen($templateName) < 64) {
            $payload['template'] = [
                'name'       => $templateName,
                'parameters' => $templateParams,
            ];
        }

        return $this->sendMessage($payload);
    }

    /**
     * Envoie un e-mail via Zavu.dev (`channel: "email"`).
     */
    public function sendEmail(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $from = config('services.zavu.from_email', 'no-reply@mivafid.com');

        return $this->sendMessage([
            'to'      => $to,
            'from'    => $from,
            'subject' => $subject,
            'html'    => $htmlBody,
            'text'    => $textBody ?? strip_tags($htmlBody),
            'channel' => 'email',
        ]);
    }

    /**
     * Exécute la requête HTTP POST vers l'endpoint /v1/messages.
     */
    private function sendMessage(array $payload): bool
    {
        $apiKey = config('services.zavu.api_key');
        if (empty($apiKey)) {
            Log::error("ZavuClient: ZAVU_API_KEY n'est pas configurée.");

            return false;
        }

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ];

            $senderId = config('services.zavu.sender_id');
            if (! empty($senderId)) {
                $headers['Zavu-Sender'] = $senderId;
            }

            Log::info("ZavuClient: tentative d'envoi [channel={$payload['channel']}] vers {$payload['to']}", [
                'payload' => $payload,
            ]);

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post("{$this->baseUrl}/messages", $payload);

            if ($response->successful()) {
                Log::info("ZavuClient: envoi réussi [channel={$payload['channel']}] vers {$payload['to']}", [
                    'response' => $response->json(),
                ]);

                return true;
            }

            Log::warning("ZavuClient: échec envoi [channel={$payload['channel']}] vers {$payload['to']}, HTTP {$response->status()}", [
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error("ZavuClient: exception lors de l'envoi [channel={$payload['channel']}] vers {$payload['to']}", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
