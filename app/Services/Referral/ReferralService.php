<?php

namespace App\Services\Referral;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyReward;
use App\Models\Referral;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Parrainage QR par carte de fidélité : chaque carte porte son propre
 * `referral_qr_token`/`referral_code` (voir `LoyaltyCard::booted()`). Le
 * scan seul, ou la simple création de compte, ne créent jamais de
 * récompense — seule `validateFirstOperation()` (appelée depuis
 * `MerchantDashboardController::grantStampOrPoints()`/`grantCashback()`
 * après la PREMIÈRE opération de fidélité valide du filleul) peut faire
 * passer un parrainage `pending` à `validated` et débloquer la récompense
 * du parrain.
 */
class ReferralService
{
    /** Préfixe distinguant un QR de parrainage d'un QR d'établissement/récompense au scan. */
    public const QR_PREFIX = 'MIVAFID-REFERRAL:';

    /** Utilisée quand l'établissement n'a configuré aucune récompense de parrainage. */
    private const DEFAULT_REWARD_TITLE = 'Récompense de parrainage';

    public function __construct(private readonly NotificationDispatcher $notifications)
    {
    }

    /**
     * Crée le parrainage `pending` reliant la carte du filleul à celle du
     * parrain. Appelée juste après la création de la `LoyaltyCard` du
     * filleul (voir `LoyaltyCardController::joinViaReferral()`) — la
     * contrainte unique `(referred_client_id, restaurant_id)` empêche
     * structurellement un second parrain pour le même filleul sur le même
     * établissement.
     */
    public function attach(LoyaltyCard $referrerCard, LoyaltyCard $referredCard): Referral
    {
        $referral = Referral::create([
            'restaurant_id' => $referredCard->restaurant_id,
            'referrer_client_id' => $referrerCard->client_id,
            'referrer_card_id' => $referrerCard->id,
            'referred_client_id' => $referredCard->client_id,
            'referred_card_id' => $referredCard->id,
            'status' => 'pending',
        ]);

        $this->notifyPending($referral);

        return $referral;
    }

    /**
     * À appeler après chaque opération de fidélité valide (tampon, point,
     * cashback) enregistrée sur la carte du filleul. Ne fait rien si aucun
     * parrainage `pending` n'existe pour cette carte, ou si ce n'est pas la
     * première opération qualifiante (le décompte inclut l'opération qui
     * vient d'être insérée, donc `count() === 1` signifie "c'est la
     * première").
     */
    public function validateFirstOperation(LoyaltyCard $referredCard): void
    {
        $referral = Referral::where('referred_card_id', $referredCard->id)
            ->where('status', 'pending')
            ->first();

        if (! $referral) {
            return;
        }

        $qualifyingOperations = DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $referredCard->id)
            ->whereIn('type', ['stamp', 'cashback_earn'])
            ->where('status', 'valid')
            ->count();

        if ($qualifyingOperations !== 1) {
            return;
        }

        DB::transaction(function () use ($referral) {
            $referrerCard = $referral->referrerCard()->first();
            $program = $referrerCard?->loyaltyProgram;
            $config = $program?->config['referral_reward'] ?? null;

            $reward = null;
            if ($config === null || ($config['enabled'] ?? true)) {
                $reward = LoyaltyReward::create([
                    'loyalty_card_id' => $referral->referrer_card_id,
                    'restaurant_id' => $referral->restaurant_id,
                    'source' => 'referral',
                    'title' => $config['label'] ?? self::DEFAULT_REWARD_TITLE,
                ]);
            }

            $referral->update([
                'status' => 'validated',
                'validated_at' => now(),
                'reward_loyalty_reward_id' => $reward?->id,
            ]);

            if ($reward !== null) {
                $this->notifyValidated($referral);
            }
        });
    }

    /**
     * Notifie le parrain dès que le filleul rejoint via son QR — avant toute
     * récompense, purement informatif ("X a rejoint grâce à vous"). Distincte
     * de [notifyValidated] : le simple scan ne débloque jamais de récompense,
     * ce message ne le laisse pas entendre.
     */
    private function notifyPending(Referral $referral): void
    {
        $referrer = $referral->referrerClient()->first();
        if (! $referrer) {
            return;
        }

        $referredName = $referral->referredClient()->first()?->first_name ?? 'Un ami';

        $this->notifications->send(
            $referrer,
            'referral_pending',
            'Parrainage en cours 👀',
            "{$referredName} a rejoint grâce à votre parrainage — votre récompense arrive dès sa première visite !",
        );

        $restaurant = \App\Models\Restaurant::find($referral->restaurant_id);
        if ($restaurant) {
            $this->notifications->send(
                $restaurant,
                'merchant_referral_new',
                'Nouveau parrainage 🤝',
                "{$referredName} a rejoint grâce au parrainage de {$referrer->first_name}.",
            );
        }
    }

    private function notifyValidated(Referral $referral): void
    {
        $referrer = $referral->referrerClient()->first();
        if (! $referrer) {
            return;
        }

        $referredName = $referral->referredClient()->first()?->first_name ?? 'Un ami';

        $this->notifications->send(
            $referrer,
            'referral_validated',
            'Parrainage validé 🎉',
            "{$referredName} a rejoint le programme grâce à vous — votre récompense est débloquée !",
        );

        $restaurant = \App\Models\Restaurant::find($referral->restaurant_id);
        if ($restaurant) {
            $this->notifications->send(
                $restaurant,
                'merchant_referral_valid',
                'Parrainage validé ✅',
                "{$referredName} a effectué sa première opération — parrainage confirmé.",
            );
        }
    }
}
