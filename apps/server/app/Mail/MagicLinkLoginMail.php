<?php

namespace App\Mail;

use App\Services\Branding\SystemMailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The magic-link login email.
 *
 * Deliberately Meridian-identified rather than organization-identified
 * (BRAND-003). It is part of the login flow, and it is sent to an address
 * before anyone is signed in — the recipient may have no account and therefore
 * no organization at all, so there is no organization identity that would be
 * truthful to use. See {@see SystemMailIdentity} for the boundary and for what
 * an organization-identified email looks like.
 */
class MagicLinkLoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly SystemMailIdentity $identity;

    public function __construct(public readonly string $verificationUrl)
    {
        $this->identity = SystemMailIdentity::meridian();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->identity->subjectFor('login link'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.magic-link-login',
            with: ['productName' => $this->identity->displayName],
        );
    }
}
