<?php

namespace Tests\Unit\Services\Otp;

use App\Services\Otp\Channels\SmsOtpChannel;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsOtpChannelTest extends TestCase
{
    public function test_sends_the_code_by_sms_via_zavu(): void
    {
        config([
            'services.zavu.api_key'   => 'zavu-secret-token',
            'services.zavu.sender_id' => 'MIVAFID',
        ]);

        Http::fake([
            'api.zavu.dev/*' => Http::response(['status' => 'success', 'message_id' => 'msg_123'], 200),
        ]);

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request->hasHeader('Authorization', 'Bearer zavu-secret-token')
                && $request->hasHeader('Zavu-Sender', 'MIVAFID')
                && $request['to'] === '+22890000001'
                && $request['channel'] === 'sms'
                && str_contains($request['text'], '123456');
        });
    }

    public function test_falls_back_to_africas_talking_when_zavu_key_not_set(): void
    {
        config([
            'services.zavu.api_key'             => null,
            'services.africastalking.username'  => 'sandbox',
            'services.africastalking.api_key'   => 'test-key',
            'services.africastalking.sender_id' => 'MIVAFID',
        ]);

        Http::fake(['api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => []]], 200)]);

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.africastalking.com/version1/messaging'
                && $request->hasHeader('apiKey', 'test-key')
                && $request['username'] === 'sandbox'
                && $request['to'] === '+22890000001'
                && str_contains($request['message'], '123456');
        });
    }

    public function test_returns_false_on_zavu_error_without_throwing(): void
    {
        config([
            'services.zavu.api_key'           => 'zavu-secret-token',
            'services.africastalking.api_key' => null,
        ]);

        Http::fake(['api.zavu.dev/*' => Http::response(['error' => 'invalid_key'], 401)]);

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
    }

    public function test_returns_false_when_all_credentials_are_missing(): void
    {
        config([
            'services.zavu.api_key'             => null,
            'services.africastalking.api_key'   => null,
            'services.africastalking.username'  => null,
        ]);
        Http::fake();

        $sent = (new SmsOtpChannel())->send('+22890000001', '123456');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }
}
