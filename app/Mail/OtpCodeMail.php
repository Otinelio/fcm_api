<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Email OTP mot de passe oublié (client ou marchand) — via Resend, voir `config/mail.php`. */
class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code)
    {
    }

    public function build(): self
    {
        return $this->subject('Votre code de vérification Miva Fid')
            ->view('emails.otp-code');
    }
}
