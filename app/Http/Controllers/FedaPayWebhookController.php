<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FedaPayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-FEDAPAY-SIGNATURE');
        $secret = config('fedapay.webhook_secret');

        try {
            // Vérifie la signature ET parse le payload en un seul appel.
            // Lève une exception si la signature ne correspond pas.
            $event = \FedaPay\Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Webhook FedaPay : payload invalide');
            return response()->json(['error' => 'invalid payload'], 400);
        } catch (\FedaPay\Error\SignatureVerification $e) {
            Log::warning('Webhook FedaPay : signature invalide - tentative de falsification possible');
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $transactionData = $event->entity ?? $event->object ?? null;
        $fedaTransactionId = $transactionData->id ?? null;

        if (!$fedaTransactionId) {
            return response()->json(['error' => 'missing transaction id'], 400);
        }

        $localTransaction = PaymentTransaction::where('fedapay_transaction_id', $fedaTransactionId)->first();

        if (!$localTransaction) {
            Log::warning("Webhook FedaPay : transaction inconnue {$fedaTransactionId}");
            return response()->json(['error' => 'unknown transaction'], 404);
        }

        // Idempotence : si on a déjà traité ce statut, on ne refait rien.
        if ($localTransaction->status === 'approved' && $event->name === 'transaction.approved') {
            return response()->json(['status' => 'already processed']);
        }

        switch ($event->name) {
            case 'transaction.approved':
                $this->activateSubscription($localTransaction, $transactionData);
                break;

            case 'transaction.canceled':
            case 'transaction.declined':
                $localTransaction->update(['status' => 'declined']);
                break;

            case 'transaction.refunded':
                $this->refundSubscription($localTransaction);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    private function activateSubscription(PaymentTransaction $localTransaction, $transactionData): void
    {
        $localTransaction->update([
            'status' => 'approved',
            'mode' => $transactionData->mode ?? null,
            'raw_payload' => (array) $transactionData,
        ]);

        $subscription = $localTransaction->subscription;
        $plan = $subscription->plan;

        $subscription->update([
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->duration_days),
        ]);

        // Pont vers FCM (voir formation FCM) : notifier le restaurant que
        // son abonnement est actif. À implémenter avec ton service FCM existant.
        // FcmService::sendToRestaurant($subscription->restaurant, 'Abonnement activé', ...);

        Log::info("Abonnement {$subscription->id} activé suite au paiement {$localTransaction->fedapay_transaction_id}");
    }

    private function refundSubscription(PaymentTransaction $localTransaction): void
    {
        $localTransaction->update(['status' => 'refunded']);
        $localTransaction->subscription->update(['status' => 'canceled']);
    }
}
