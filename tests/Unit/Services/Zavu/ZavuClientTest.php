<?php

namespace Tests\Unit\Services\Zavu;

use App\Services\Zavu\ZavuClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZavuClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.zavu.api_key'           => 'zavu-test-secret',
            'services.zavu.sender_id'         => 'MIVAFID',
            'services.zavu.whatsapp_template' => 'otp_verification',
            'services.zavu.from_email'        => 'no-reply@mivafid.com',
        ]);
    }

    public function test_sends_sms_successfully(): void
    {
        Http::fake([
            'api.zavu.dev/*' => Http::response(['status' => 'success', 'id' => 'msg_sms_1'], 200),
        ]);

        $client = new ZavuClient();
        $sent = $client->sendSms('+22890000001', 'Code test : 123456');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request->hasHeader('Authorization', 'Bearer zavu-test-secret')
                && $request->hasHeader('Zavu-Sender', 'MIVAFID')
                && $request['to'] === '+22890000001'
                && $request['channel'] === 'sms'
                && $request['text'] === 'Code test : 123456';
        });
    }

    public function test_sends_whatsapp_successfully(): void
    {
        Http::fake([
            'api.zavu.dev/*' => Http::response(['status' => 'success', 'id' => 'msg_wa_1'], 200),
        ]);

        $client = new ZavuClient();
        $sent = $client->sendWhatsApp('+22890000001', 'Code test : 123456', 'otp_template', ['123456']);

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request['channel'] === 'whatsapp'
                && $request['to'] === '+22890000001'
                && $request['template']['name'] === 'otp_template';
        });
    }

    public function test_sends_email_successfully(): void
    {
        Http::fake([
            'api.zavu.dev/*' => Http::response(['status' => 'success', 'id' => 'msg_mail_1'], 200),
        ]);

        $client = new ZavuClient();
        $sent = $client->sendEmail('client@example.com', 'Votre code OTP', '<p>Votre code est 123456</p>');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request['channel'] === 'email'
                && $request['to'] === 'client@example.com'
                && $request['from'] === 'no-reply@mivafid.com'
                && $request['subject'] === 'Votre code OTP'
                && str_contains($request['html'], '123456');
        });
    }

    public function test_returns_false_when_api_key_is_missing(): void
    {
        config(['services.zavu.api_key' => null]);
        Http::fake();

        $client = new ZavuClient();
        $this->assertFalse($client->isConfigured());

        $sent = $client->sendSms('+22890000001', '123456');
        $this->assertFalse($sent);
        Http::assertNothingSent();
    }
}
