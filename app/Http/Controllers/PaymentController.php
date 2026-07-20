<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function initSubscriptionPayment(Request $request, SubscriptionPlan $plan)
    {
        // adapte selon ta relation user -> restaurant
        // Here we create a mock restaurant for the user if it doesn't exist
        $restaurant = \App\Models\Restaurant::firstOrCreate(['name' => $request->user()->name . ' Restaurant']);
        $user = $request->user();

        // 1. On crée d'abord l'abonnement en "pending" — il ne devient "active"
        // qu'au moment où le webhook confirme le paiement (Module 6).
        $subscription = RestaurantSubscription::create([
            'restaurant_id' => $restaurant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'pending',
        ]);

        // 2. On crée la transaction FedaPay côté API.
        $fedaTransaction = \FedaPay\Transaction::create([
            'description' => "Abonnement {$plan->name} - {$restaurant->name}",
            'amount' => $plan->price_xof,
            'currency' => ['iso' => 'XOF'],
            'callback_url' => config('app.url') . '/paiement/retour',
            'customer' => [
                'firstname' => $user->name,
                'lastname' => 'Admin',
                'email' => $user->email,
            ],
            // metadata permet de retrouver notre subscription depuis le webhook
            'custom_metadata' => [
                'restaurant_subscription_id' => $subscription->id,
            ],
        ]);

        // 3. On garde une trace locale immédiatement, statut "pending".
        PaymentTransaction::create([
            'restaurant_subscription_id' => $subscription->id,
            'fedapay_transaction_id' => $fedaTransaction->id,
            'fedapay_reference' => $fedaTransaction->reference ?? null,
            'amount_xof' => $plan->price_xof,
            'status' => 'pending',
            'raw_payload' => json_decode(json_encode($fedaTransaction), true),
        ]);

        // 4. On génère le lien de paiement hébergé FedaPay.
        $token = $fedaTransaction->generateToken();

        return response()->json([
            'payment_url' => $token->url,
            'subscription_id' => $subscription->id,
        ]);
    }
}
