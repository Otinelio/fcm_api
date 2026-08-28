<?php

namespace Tests\Unit\Services\Otp;

use App\Mail\OtpCodeMail;
use App\Services\Otp\Channels\SmsOtpChannel;
use App\Services\Otp\Channels\WhatsAppOtpChannel;
use App\Services\Otp\OtpDeliveryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `shouldSkipRealDelivery()` est surchargée (sous-classe anonyme) dans les
 * tests qui exercent un vrai envoi — en environnement de test normal, ce
 * garde-fou bloque tout appel réseau réel (voir
 * `test_skips_delivery_entirely_in_the_test_environment`).
 */
class OtpDeliveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dialog360.api_key' => 'test-key',
            'services.africastalking.username' => 'sandbox',
            'services.africastalking.api_key' => 'test-key',
        ]);
    }

    /** Constructeur sans le conteneur : les deux channels réels, mais le garde-fou levé. */
    private function serviceWithDeliveryForced(): OtpDeliveryService
    {
        return new class(new WhatsAppOtpChannel(), new SmsOtpChannel()) extends OtpDeliveryService {
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

    public function test_email_identifier_is_sent_by_mail(): void
    {
        Mail::fake();

        $this->serviceWithDeliveryForced()->send('client@example.com', '123456');

        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->code === '123456');
    }

    public function test_phone_identifier_tries_whatsapp_first_and_stops_there_on_success(): void
    {
        Http::fake(['waba-v2.360dialog.io/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $this->serviceWithDeliveryForced()->send('+22890000001', '123456');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '360dialog.io'));
    }

    public function test_falls_back_to_sms_when_whatsapp_fails(): void
    {
        Http::fake([
            'waba-v2.360dialog.io/*' => Http::response(['error' => 'not on whatsapp'], 400),
            'api.africastalking.com/*' => Http::response(['SMSMessageData' => []], 200),
        ]);

        $this->serviceWithDeliveryForced()->send('+22890000001', '123456');

        Http::assertSent(fn ($r) => str_contains($r->url(), '360dialog.io'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'africastalking.com'));
    }

    public function test_does_not_throw_when_both_phone_channels_fail(): void
    {
        Http::fake([
            'waba-v2.360dialog.io/*' => Http::response([], 500),
            'api.africastalking.com/*' => Http::response([], 500),
        ]);

        $this->serviceWithDeliveryForced()->send('+22890000001', '123456');

        Http::assertSentCount(2);
    }
}
