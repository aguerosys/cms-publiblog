<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TwoFactorCode extends Mailable
{
    public function __construct(public readonly string $code, public readonly int $minutes)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código de acceso a Publiblog');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.two-factor-code');
    }
}
