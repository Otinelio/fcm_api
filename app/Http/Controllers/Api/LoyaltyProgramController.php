<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreLoyaltyProgramRequest;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LoyaltyProgramController extends Controller
{
    /**
     * POST /api/loyalty-programs
     *
     * Crée ou remplace le programme de fidélité du restaurant authentifié
     * (step2/3 de l'onboarding marchand — "Activer mon programme").
     *
     * La table `loyalty_programs` (multi-tenant, préexistante) a un schéma
     * générique `name/type/config` : les champs spécifiques au wizard
     * (couleurs, style de tampon, récompense...) sont regroupés dans `config`.
     */
    public function store(StoreLoyaltyProgramRequest $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $data = $request->validated();
        $isCashback = $data['mode'] === 'cashback';

        // Changer de type de programme (Tampons -> Cashback, etc.) alors que
        // des cartes existent rendrait les compteurs existants incohérents
        // (stamps_current ignorés, solde cashback à zéro) : refus explicite.
        // Requête directe plutôt que la relation : l'instance utilisateur
        // résolue par le garde d'auth peut être partagée entre requêtes
        // (cache du guard en tests), avec un cache de relation périmé.
        $existingProgram = LoyaltyProgram::query()
            ->where('restaurant_id', $restaurant->id)
            ->first();
        if ($existingProgram
            && $existingProgram->type !== $data['mode']
            && LoyaltyCard::where('restaurant_id', $restaurant->id)->exists()
        ) {
            return response()->json([
                'message' => 'Impossible de changer le type de programme : des clients ont déjà une carte de fidélité.',
            ], 422);
        }

        $program = DB::transaction(function () use ($restaurant, $data, $isCashback) {
            $program = $restaurant->loyaltyProgram()->updateOrCreate(
                ['restaurant_id' => $restaurant->id],
                [
                    'name' => $restaurant->name ?? 'Programme de fidélité',
                    'type' => $data['mode'],
                    'is_active' => true,
                    'loops' => $data['loops'] ?? true,
                    'config' => [
                        'reward_validity_days' => $data['reward_validity_days'] ?? null,
                        'show_review_button' => $data['show_review_button'] ?? false,
                        'google_review_url' => $data['google_review_url'] ?? null,
                        'color_primary' => $data['color_primary'],
                        'color_secondary' => $data['color_secondary'],
                        'stamp_design_type' => $data['stamp_design_type'],
                        'stamp_emoji' => $data['stamp_emoji'] ?? null,
                        'stamp_icon' => $data['stamp_icon'] ?? null,
                        'card_decoration_pattern' => $data['card_decoration_pattern'] ?? null,
                        'card_gradient_type' => $data['card_gradient_type'] ?? null,
                        'logo_url' => $data['logo_url'] ?? null,
                        // Taux de conversion mode "Achat", réglable par restaurant
                        // (100 FCFA = 1 point par défaut) — auparavant figé à 500
                        // en dur, ignorant la valeur réellement soumise.
                        'fcfa_per_point' => $data['mode'] === 'spend'
                            ? ($data['fcfa_per_point'] ?? 100)
                            : null,
                        'cashback_percentage' => $isCashback ? $data['cashback_percentage'] : null,
                        'cashback_redeem_threshold_fcfa' => $isCashback ? ($data['cashback_redeem_threshold_fcfa'] ?? null) : null,
                        // Expiration du solde cashback après N jours sans crédit
                        // (spec §4.1/§12, optionnelle) — `null` = pas d'expiration.
                        'cashback_expiry_days' => $isCashback ? ($data['cashback_expiry_days'] ?? null) : null,
                        // Base de progression des paliers cashback — voir
                        // `LoyaltyTierService::lifetimeMetric`. `null` pour
                        // les autres types (non applicable).
                        'cashback_tier_basis' => $isCashback ? ($data['cashback_tier_basis'] ?? 'cumulative') : null,
                        // Récompense anniversaire — indépendante du mode, voir
                        // `SendBirthdayNotifications`.
                        'birthday_reward' => [
                            'enabled' => $data['birthday_reward_enabled'] ?? false,
                            'title' => $data['birthday_reward_title'] ?? null,
                            'description' => $data['birthday_reward_description'] ?? null,
                            'validity_days' => $data['birthday_reward_validity_days'] ?? null,
                            'surprise' => $data['birthday_reward_surprise'] ?? false,
                        ],
                        // Récompense de parrainage — voir `ReferralService::validateFirstOperation()`.
                        // `enabled` par défaut à `true` (absent = récompense
                        // générique accordée) pour que le parrainage
                        // fonctionne dès l'activation du programme, même
                        // sans réglage explicite du marchand.
                        'referral_reward' => [
                            'enabled' => $data['referral_reward_enabled'] ?? true,
                            'label' => $data['referral_reward_label'] ?? null,
                        ],
                    ],
                ],
            );

            // Paliers unifiés (niveau + récompense) — table dédiée
            // `loyalty_program_tiers`, mise à jour par upsert clé sur `order`
            // (pas delete+recreate) pour préserver les ids existants : les
            // `loyalty_rewards.program_tier_id` déjà émis restent liés, et un
            // ré-enregistrement du même palier (goal/nom/récompense inchangés)
            // ne change pas son id.
            $tiers = $data['tiers'] ?? [];
            foreach ($tiers as $index => $tier) {
                $program->tiers()->updateOrCreate(
                    ['loyalty_program_id' => $program->id, 'order' => $index + 1],
                    [
                        'goal' => (int) $tier['goal'],
                        'level_name' => $tier['level_name'] ?? null,
                        'icon_key' => $tier['icon_key'] ?? null,
                        'reward_description' => $tier['reward_description'] ?? null,
                        'reveal_reward' => $tier['reveal_reward'] ?? true,
                        'validity_days' => $tier['validity_days'] ?? null,
                    ],
                );
            }
            $program->tiers()->where('order', '>', count($tiers))->delete();

            return $program;
        });

        return response()->json([
            'message' => 'Programme de fidélité enregistré.',
            'loyalty_program' => $program->load('tiers'),
        ], 201);
    }
}
