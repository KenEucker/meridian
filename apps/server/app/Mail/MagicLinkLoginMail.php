<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MagicLinkLoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $verificationUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Meridian login link',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.magic-link-login',
        );
    }
}
