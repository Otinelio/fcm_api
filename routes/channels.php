<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('loyalty.{customerId}', function ($user, $customerId) {
    return $user instanceof \App\Models\Client && (int) $user->id === (int) $customerId;
});

// Canal marchand — même paire d'événements (`LoyaltyCardUpdated`,
// `LoyaltyRewardUpdated`) que `loyalty.{customerId}`, diffusée aussi ici pour
// que le dashboard marchand se synchronise en direct (historique, solde,
// progression, niveaux, récompenses) sans refresh manuel. Le marchand
// s'authentifie toujours en tant que `Restaurant` (pas de guard staff séparé
// à ce jour, voir `CurrentActor`).
Broadcast::channel('merchant.{restaurantId}', function ($user, $restaurantId) {
    return $user instanceof \App\Models\Restaurant && (int) $user->id === (int) $restaurantId;
});

Broadcast::channel('customer.{customerId}', function ($user, int $customerId) {
    if ((int) $user->id !== $customerId) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
