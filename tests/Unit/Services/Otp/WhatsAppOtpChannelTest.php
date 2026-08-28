<?php

namespace Tests\Unit\Services\Otp;

use App\Services\Otp\Channels\WhatsAppOtpChannel;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppOtpChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dialog360.api_key' => 'test-key',
            'services.dialog360.otp_template' => 'otp_verification',
            'services.dialog360.template_lang' => 'fr',
        ]);
    }

    public function test_sends_an_authentication_template_with_the_code(): void
    {
        Http::fake(['waba-v2.360dialog.io/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $sent = (new WhatsAppOtpChannel())->send('+22890000001', '123456');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://waba-v2.360dialog.io/messages'
                && $request->hasHeader('D360-API-KEY', 'test-key')
                // Le numéro part sans le "+" attendu par la Cloud API.
                && $request['to'] === '22890000001'
                && $request['template']['name'] === 'otp_verification'
                && $request['template']['components'][0]['parameters'][0]['text'] === '123456';
        });
    }

    public function test_returns_false_on_provider_error_without_throwing(): void
    {
        Http::fake(['waba-v2.360dialog.io/*' => Http::response(['error' => 'not on whatsapp'], 400)]);

        $sent = (new WhatsAppOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
    }

    public function test_returns_false_when_credentials_are_missing(): void
    {
        config(['services.dialog360.api_key' => null]);
        Http::fake();

        $sent = (new WhatsAppOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_network_failure_returns_false_instead_of_throwing(): void
    {
        Http::fake(['waba-v2.360dialog.io/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $sent = (new WhatsAppOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
    }
}
