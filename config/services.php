<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'firebase' => [
        // Doit être le projet Firebase qui a émis les id_token de l'app mobile
        // (celui de son google-services.json). Un projet différent ici et le
        // jeton est rejeté avec « Token Firebase invalide ».
        'project_id' => env('FIREBASE_PROJECT_ID', ''),

        // Chemin du compte de service (FCM). Relatif => résolu depuis la racine
        // du projet, ce qui rend FIREBASE_CREDENTIALS utilisable tel quel.
        'credentials' => (function () {
            $path = env('FIREBASE_CREDENTIALS', 'storage/app/firebase/service-account.json');

            return str_starts_with($path, '/') ? $path : base_path($path);
        })(),

        // Tolérance d'horloge à la vérification des id_token. Un serveur qui
        // dérive de quelques secondes rejetterait sinon des jetons valides
        // avec « token used too early ».
        'leeway' => (int) env('FIREBASE_TOKEN_LEEWAY', 60),
    ],

    // ── OAuth Providers (validation de tokens côté serveur) ──────────
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'), // Bundle ID iOS
    ],

];
