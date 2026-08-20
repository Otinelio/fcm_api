<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('loyalty.{customerId}', function ($user, $customerId) {
    return $user instanceof \App\Models\Client && (int) $user->id === (int) $customerId;
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
