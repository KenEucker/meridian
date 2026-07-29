<?php

namespace App\Mail;

use App\Services\Branding\SystemMailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The API login code email (AUTH-019; technical spec 11.4).
 *
 * Meridian-identified rather than organization-identified for the same reason
 * as {@see MagicLinkLoginMail}: it is part of the login flow BRAND-003 protects,
 * and it is sent to an address before anyone is signed in, so the recipient may
 * have no organization whose identity would be truthful to use.
 *
 * It carries a code rather than a link because the client application the user
 * started in completes the exchange itself, so there is nowhere for a link to
 * usefully send them.
 */
class ApiLoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly SystemMailIdentity $identity;

    public function __construct(
        public readonly string $code,
        public readonly int $expiresMinutes,
    ) {
        $this->identity = SystemMailIdentity::meridian();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->identity->subjectFor('login code'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.api-login-code',
            with: ['productName' => $this->identity->displayName],
        );
    }
}
