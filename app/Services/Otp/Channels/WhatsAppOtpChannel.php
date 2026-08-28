<?php

namespace App\Services\Otp\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi d'un code OTP par WhatsApp via 360dialog (BSP officiel de la Cloud
 * API Meta). Utilise un modèle de message catégorie "Authentification" —
 * approbation généralement rapide côté WhatsApp Business Manager,
 * contrairement aux modèles marketing.
 *
 * Pas de vérification préalable "ce numéro a-t-il WhatsApp" : Meta a retiré
 * cet endpoint avec la Cloud API (il existait sur l'ancienne API
 * "On-Premises", `/v1/contacts`). La méthode ici est celle standard de
 * l'industrie — tenter l'envoi et laisser `OtpDeliveryService` basculer sur
 * SMS si `send()` renvoie `false`, quelle que soit la cause de l'échec
 * (numéro absent de WhatsApp, compte injoignable, panne du fournisseur...).
 */
class WhatsAppOtpChannel
{
    public function send(string $phoneE164, string $code): bool
    {
        $apiKey = config('services.dialog360.api_key');
        if (! $apiKey) {
            return false;
        }

        try {
            $response = Http::withHeaders(['D360-API-KEY' => $apiKey])
                ->timeout(8)
                ->post('https://waba-v2.360dialog.io/messages', [
                    // La Cloud API attend le numéro sans le préfixe "+".
                    'to' => ltrim($phoneE164, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => config('services.dialog360.otp_template'),
                        'language' => ['code' => config('services.dialog360.template_lang', 'fr')],
                        // Un seul paramètre corps (le code) — à ajuster si le
                        // modèle approuvé porte aussi un bouton "copier le code"
                        // (composant `button`/`url` additionnel).
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $code],
                                ],
                            ],
                        ],
                    ],
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning("WhatsAppOtpChannel: échec envoi vers {$phoneE164}, repli SMS.", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
