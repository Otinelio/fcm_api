<?php

namespace App\Services\Fraud;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionFraudDetectionService
{
    public function __construct(
        private readonly \App\Services\NotificationDispatcher $notifications,
    ) {
    }

    /**
     * Evaluate a transaction for potential fraud.
     * Throws a ValidationException if fraud is detected, as requested by the user.
     *
     * @param LoyaltyCard $card
     * @param LoyaltyProgram $program
     * @param float|null $amountFcfa The purchase amount (for spend or cashback programs)
     * @param float $earnedValue The amount of stamps, points, or cashback earned
     * @param string $type The transaction type (e.g. 'stamp', 'cashback_earn')
     * @param \App\Models\Restaurant $restaurant
     * @throws ValidationException
     */
    public function validateAndThrowIfSuspicious(
        LoyaltyCard $card,
        LoyaltyProgram $program,
        ?float $amountFcfa,
        float $earnedValue,
        string $type,
        \App\Models\Restaurant $restaurant
    ): void {
        $reason = null;

        // 1. Check for frequent scans (e.g., > 3 scans within the last 1 hour)
        $recentScansCount = DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->whereIn('type', ['stamp', 'cashback_earn'])
            ->where('status', 'valid')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentScansCount >= 3) {
            $reason = "Plus de 3 scans effectués au cours de la dernière heure pour la carte de ce client.";
        }

        // 2. Check for unusually large purchases
        if (!$reason && $amountFcfa !== null && $amountFcfa > 500000) {
            $reason = "Le montant de l'achat (" . number_format($amountFcfa, 0, ',', ' ') . " FCFA) dépasse la limite de sécurité (500 000 FCFA).";
        }

        // 3. Check for massive credits
        if (!$reason) {
            if ($type === 'stamp') {
                if ($program->type === 'stamps' && $earnedValue > 10) {
                    $reason = "Tentative d'ajout de plus de 10 tampons en une seule fois.";
                } elseif ($program->type === 'spend' && $earnedValue > 10000) {
                    $reason = "Tentative d'ajout de plus de 10 000 points en une seule fois.";
                }
            } elseif ($type === 'cashback_earn' && $earnedValue > 10000) {
                $reason = "Tentative d'ajout de plus de 10 000 FCFA de cashback en une seule fois.";
            }
        }

        if ($reason) {
            $client = $card->client()->first();
            $clientName = $client ? "{$client->first_name} {$client->last_name}" : 'un client';
            $fullReason = "$reason (Client : $clientName)";

            $this->notifications->send(
                $restaurant,
                'fraud_alert',
                'Action bloquée : Risque de fraude',
                $fullReason,
                ['client_id' => $card->client_id]
            );

            throw ValidationException::withMessages([
                'fraud' => "Action bloquée : $reason",
            ]);
        }
    }
}
