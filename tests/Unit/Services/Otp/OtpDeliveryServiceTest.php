<?php

namespace Tests\Unit\Services\Otp;

use App\Mail\OtpCodeMail;
use App\Services\Otp\Channels\SmsOtpChannel;
use App\Services\Otp\Channels\WhatsAppOtpChannel;
use App\Services\Otp\Channels\ZavuEmailChannel;
use App\Services\Otp\Channels\ZavuWhatsAppChannel;
use App\Services\Otp\OtpDeliveryService;
use App\Services\Zavu\ZavuClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpDeliveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.zavu.api_key'            => 'zavu-test-key',
            'services.dialog360.api_key'       => '360dialog-test-key',
            'services.africastalking.username' => 'sandbox',
            'services.africastalking.api_key'  => 'at-test-key',
        ]);
    }

    private function serviceWithDeliveryForced(): OtpDeliveryService
    {
        $zavuClient = new ZavuClient();

        return new class(
            new WhatsAppOtpChannel(),
            new SmsOtpChannel($zavuClient),
            new ZavuWhatsAppChannel($zavuClient),
            new ZavuEmailChannel($zavuClient)
        ) extends OtpDeliveryService {
            protected function shouldSkipRealDelivery(): bool
            {
                return false;
            }
        };
    }

    public function test_skips_delivery_entirely_in_the_test_environment(): void
    {
        Http::fake();
        Mail::fake();

        app(OtpDeliveryService::class)->send('client@example.com', '123456');
        app(OtpDeliveryService::class)->send('+22890000001', '123456');

        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_email_identifier_is_sent_by_zavu_email(): void
    {
        Http::fake([
            'api.zavu.dev/*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->serviceWithDeliveryForced()->send('client@example.com', '123456');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request['channel'] === 'email'
                && $request['to'] === 'client@example.com'
                && str_contains($request['html'], '123456');
        });
    }

    public function test_email_identifier_falls_back_to_laravel_mail_when_zavu_fails(): void
    {
        Http::fake([
            'api.zavu.dev/*' => Http::response(['error' => 'failed'], 500),
        ]);
        Mail::fake();

        $this->serviceWithDeliveryForced()->send('client@example.com', '123456');

        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->code === '123456');
    }

    public function test_phone_identifier_sends_directly_via_zavu_sms(): void
    {
        Http::fake([
            'api.zavu.dev/*' => Http::response(['status' => 'success'], 200),
        ]);

        $this->serviceWithDeliveryForced()->send('+22890000001', '123456');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zavu.dev/v1/messages'
                && $request['channel'] === 'sms'
                && $request['to'] === '+22890000001';
        });
    }

    public function test_falls_back_to_africas_talking_when_zavu_sms_fails(): void
    {
        Http::fake([
            'api.zavu.dev/*'          => Http::response(['error' => 'zavu sms failed'], 400),
            'api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]], 200),
        ]);

        $this->serviceWithDeliveryForced()->send('+22890000001', '123456');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'zavu.dev') && $r['channel'] === 'sms');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'africastalking.com'));
    }

    public function test_does_not_throw_when_all_phone_channels_fail(): void
    {
        Http::fake([
            'api.zavu.dev/*'          => Http::response([], 500),
            'api.africastalking.com/*' => Http::response([], 500),
        ]);

        $this->serviceWithDeliveryForced()->send('+22890000001', '123456');

        // Ne doit lever aucune exception
        $this->assertTrue(true);
    }
}
