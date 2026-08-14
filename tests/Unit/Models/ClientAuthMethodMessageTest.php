<?php

namespace Tests\Unit\Models;

use App\Models\Client;
use Tests\TestCase;

class ClientAuthMethodMessageTest extends TestCase
{
    public function test_google_account_message_names_the_provider(): void
    {
        $client = new Client(['oauth_provider' => 'google']);

        $this->assertSame(
            'Ce compte utilise une connexion Google. Connectez-vous avec Google pour accéder à votre compte.',
            $client->authMethodDeniedMessage(),
        );
    }

    public function test_apple_account_message_names_the_provider(): void
    {
        $client = new Client(['oauth_provider' => 'apple']);

        $this->assertSame(
            'Ce compte utilise une connexion Apple. Connectez-vous avec Apple pour accéder à votre compte.',
            $client->authMethodDeniedMessage(),
        );
    }

    public function test_classic_account_message_points_to_password_login(): void
    {
        $client = new Client(['oauth_provider' => null]);

        $this->assertSame(
            'Un compte existe déjà avec cet e-mail et utilise un mot de passe. Connectez-vous avec votre mot de passe.',
            $client->authMethodDeniedMessage(),
        );
    }
}
