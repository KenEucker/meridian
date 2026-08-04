<?php

namespace App\Mail;

use App\Services\Branding\SystemMailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The applicant portal link email (M18.22; APP-012).
 *
 * Meridian-identified rather than organization-identified, for the reason
 * {@see SystemMailIdentity} draws the boundary on and one more of its own: this
 * is sent to an address before anybody is signed in, and the applications it
 * opens onto may have been made to more than one organization. There is no
 * single organization whose identity would be truthful on the envelope.
 *
 * It is not a notification. The NOTIFY-001 set is closed and this is not in it:
 * nothing operational happened, somebody asked to see their own record. It is
 * mailed on the same footing as the login link, which is what APP-012 means by
 * the same mechanism as primary email verification.
 */
class ApplicantPortalLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly SystemMailIdentity $identity;

    public function __construct(
        public readonly string $portalUrl,
        public readonly int $expiresMinutes,
    ) {
        $this->identity = SystemMailIdentity::meridian();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->identity->subjectFor('applications'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.applicant-portal-link',
            with: [
                'productName' => $this->identity->displayName,
                'expiresMinutes' => $this->expiresMinutes,
            ],
        );
    }
}
