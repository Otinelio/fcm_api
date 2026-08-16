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

        $program = $restaurant->loyaltyProgram()->updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'name'      => $restaurant->name ?? 'Programme de fidélité',
                'type'      => $data['mode'],
                'is_active' => true,
                'config'    => [
                    'goal'                    => $data['goal'],
                    'reward_description'      => $data['reward_description'] ?? null,
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
                ],
            ],
        );

        return response()->json([
            'message'         => 'Programme de fidélité enregistré.',
            'loyalty_program' => $program,
        ], 201);
    }
}
