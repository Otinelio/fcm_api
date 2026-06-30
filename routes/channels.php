<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('loyalty.{customerId}', function ($user, $customerId) {
    return (int) $user->id === (int) $customerId;
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
