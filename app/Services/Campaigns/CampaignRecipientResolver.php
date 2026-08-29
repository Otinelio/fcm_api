<?php

namespace App\Services\Campaigns;

use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * Résout les `client_id` ciblés par un type de destinataire de campagne.
 * Utilisé à la création de la campagne (figer la liste dans `target`) et par
 * la commande planifiée (relit la liste figée, ne recalcule jamais).
 */
class CampaignRecipientResolver
{
    /**
     * Seuil (pourcentage de progression vers le prochain palier) à partir
     * duquel une carte est considérée "proche d'une récompense" — voir le
     * cas `near_reward` ci-dessous.
     */
    private const NEAR_REWARD_PERCENT_THRESHOLD = 80;

    /** @return Collection<int, int> */
    public function resolve(Restaurant $restaurant, string $type): Collection
    {
        if ($type === 'near_reward') {
            return $this->resolveNearReward($restaurant);
        }

        $query = LoyaltyCard::where('restaurant_id', $restaurant->id);

        if ($type === 'inactive') {
            $query->where(function ($q) {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', now()->subDays(30));
            });
        } elseif ($type === 'reward_available') {
            $query->where('status', 'reward_available');
        }

        return $query->pluck('client_id')->unique()->values();
    }

    /**
     * "Proches d'une récompense" (libellé Flutter) — distinct de
     * `reward_available` (déjà débloquée, colonne `status`) : ici la carte
     * n'a PAS encore de récompense prête, mais sa progression vers le
     * prochain palier (accesseur `percent`, calculé — pas une colonne, donc
     * pas filtrable en SQL) dépasse le seuil. Nécessite de charger les
     * modèles (avec leur programme, dont dépend l'accesseur) plutôt qu'un
     * simple `pluck`.
     */
    private function resolveNearReward(Restaurant $restaurant): Collection
    {
        return LoyaltyCard::where('restaurant_id', $restaurant->id)
            ->where('status', '!=', 'reward_available')
            ->with('loyaltyProgram')
            ->get()
            ->filter(fn (LoyaltyCard $card) => $card->percent >= self::NEAR_REWARD_PERCENT_THRESHOLD)
            ->pluck('client_id')
            ->unique()
            ->values();
    }
}
