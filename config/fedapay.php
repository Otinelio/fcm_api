<?php

// Configuration centralisée FedaPay.
// Toutes les clés viennent du .env, jamais codées en dur ici.
return [
    'secret_key' => env('FEDAPAY_SECRET_KEY'),
    'public_key' => env('FEDAPAY_PUBLIC_KEY'),
    'environment' => env('FEDAPAY_ENVIRONMENT', 'sandbox'),
    'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),
];
