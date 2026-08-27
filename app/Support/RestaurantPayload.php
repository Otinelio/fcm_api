<?php

namespace App\Support;

use App\Models\Restaurant;

final class RestaurantPayload
{
    private const DEFAULT_NOTIFICATION_PREFERENCES = [
        'new_client' => true,
        'reward' => true,
        'low_sms' => true,
        'weekly_report' => false,
        'promotions' => false,
    ];

    public static function build(Restaurant $restaurant): array
    {
        return [
            'id' => $restaurant->id,
            'uuid' => $restaurant->uuid,
            'name' => $restaurant->name,
            'category' => $restaurant->category,
            'email' => $restaurant->email,
            'phone' => $restaurant->phone,
            'address' => $restaurant->address,
            'city' => $restaurant->city,
            'country' => $restaurant->country,
            'description' => $restaurant->description,
            'logo_url' => $restaurant->logo_url,
            'whatsapp' => $restaurant->whatsapp,
            'instagram' => $restaurant->instagram,
            'facebook' => $restaurant->facebook,
            'tiktok' => $restaurant->tiktok,
            'qr_token' => $restaurant->qr_token,
            'short_code' => $restaurant->short_code,
            'has_business_info' => $restaurant->hasBusinessInfo(),
            'latitude' => $restaurant->location?->latitude,
            'longitude' => $restaurant->location?->longitude,
            'has_location' => $restaurant->hasLocation(),
            'opening_hours' => $restaurant->opening_hours,
            'has_loyalty_program' => $restaurant->hasLoyaltyProgram(),
            // Config du programme (couleurs, mode, objectif, style de tampon) :
            // le dashboard marchand la rejoue telle quelle, sans second appel.
            'loyalty_program' => $restaurant->loyaltyProgram
                ? [
                    'type' => $restaurant->loyaltyProgram->type,
                    'config' => [
                        ...$restaurant->loyaltyProgram->config ?? [],
                        'loops' => $restaurant->loyaltyProgram->loops,
                        'tiers' => $restaurant->loyaltyProgram->tiers->map(fn ($t) => [
                            'goal' => $t->goal,
                            'level_name' => $t->level_name,
                            'reward_description' => $t->reward_description,
                            'reveal_reward' => $t->reveal_reward,
                            'validity_days' => $t->validity_days,
                        ])->all(),
                    ],
                ]
                : null,
            'plan' => $restaurant->planSlug(),
            'sms_credits' => (int) $restaurant->sms_credits,
            'notification_preferences' => [
                ...self::DEFAULT_NOTIFICATION_PREFERENCES,
                ...$restaurant->notification_preferences ?? [],
            ],
            'created_at' => $restaurant->created_at?->toIso8601String(),
        ];
    }
}
