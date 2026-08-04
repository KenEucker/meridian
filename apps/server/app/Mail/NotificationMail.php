<?php

namespace App\Mail;

use App\Services\Branding\SystemMailIdentity;
use App\Services\Notifications\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every transactional notification, in one mailable (M18.21; NOTIFY-003,
 * NOTIFY-004).
 *
 * One class rather than ten, because the ten differ only in the words they
 * carry and nothing about the envelope, the branding, or the layout changes
 * between them. Composing the words is {@see NotificationMessage}'s job, and
 * splitting a mailable per notification type would have put a tenth of the
 * branding rule in ten files.
 *
 * The envelope carries the organization's name where the organization has a
 * branding profile and Meridian's where it does not — the
 * {@see SystemMailIdentity} boundary BRAND-002 draws,
 * and the opposite decision from the login mails, which stay Meridian's under
 * BRAND-003 because they reach an address before anyone is signed in.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly NotificationMessage $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The from address stays the node's configured sender: an
            // organization's branding replaces the name it signs itself with,
            // not the domain the mail is authenticated against, and rewriting
            // the address per organization would fail SPF and DKIM on every
            // send.
            from: new Address(
                (string) config('mail.from.address'),
                $this->notification->senderName(),
            ),
            subject: $this->notification->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
            // Deliberately not `message`: Laravel shares the
            // Illuminate\Mail\Message being built under that name, and a view
            // variable of the same name is silently the framework's rather than
            // ours.
            with: ['notification' => $this->notification],
        );
    }
}
