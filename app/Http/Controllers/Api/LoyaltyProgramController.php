<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreLoyaltyProgramRequest;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

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

        // Cashback n'a ni objectif ni palier de récompense (pas de cycle) —
        // `goal`/`rewards` ne sont ni envoyés ni exigés pour ce mode.
        $rewards = $isCashback ? null : ($data['rewards'] ?? [
            [
                'goal'               => (int) $data['goal'],
                'reward_description' => $data['reward_description'] ?? '',
            ],
        ]);

        $program = $restaurant->loyaltyProgram()->updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'name'      => $restaurant->name ?? 'Programme de fidélité',
                'type'      => $data['mode'],
                'is_active' => true,
                'config'    => [
                    'goal'                    => $isCashback ? null : $data['goal'],
                    'reward_description'      => $isCashback ? null : ($data['reward_description'] ?? null),
                    'rewards'                 => $rewards,
                    'levels'                  => $data['levels'] ?? null,
                    'reward_validity_days'    => $data['reward_validity_days'] ?? null,
                    'show_review_button'      => $data['show_review_button'] ?? false,
                    'google_review_url'       => $data['google_review_url'] ?? null,
                    'color_primary'           => $data['color_primary'],
                    'color_secondary'         => $data['color_secondary'],
                    'stamp_design_type'       => $data['stamp_design_type'],
                    'stamp_emoji'             => $data['stamp_emoji'] ?? null,
                    'stamp_icon'              => $data['stamp_icon'] ?? null,
                    'card_decoration_pattern' => $data['card_decoration_pattern'] ?? null,
                    'card_gradient_type'      => $data['card_gradient_type'] ?? null,
                    'logo_url'                => $data['logo_url'] ?? null,
                    // Taux de conversion mode "Achat", réglable par restaurant
                    // (100 FCFA = 1 point par défaut) — auparavant figé à 500
                    // en dur, ignorant la valeur réellement soumise.
                    'fcfa_per_point'          => $data['mode'] === 'spend'
                        ? ($data['fcfa_per_point'] ?? 100)
                        : null,
                    'cashback_percentage'        => $isCashback ? $data['cashback_percentage'] : null,
                    'cashback_redeem_cap_percent' => $isCashback ? ($data['cashback_redeem_cap_percent'] ?? null) : null,
                    // Expiration du solde cashback après N jours sans crédit
                    // (spec §4.1/§12, optionnelle) — `null` = pas d'expiration.
                    'cashback_expiry_days'       => $isCashback ? ($data['cashback_expiry_days'] ?? null) : null,
                ],
            ],
        );

        return response()->json([
            'message'         => 'Programme de fidélité enregistré.',
            'loyalty_program' => $program,
        ], 201);
    }
}
