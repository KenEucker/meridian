<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Event;

/**
 * An event's mark (BRAND-028).
 *
 * One value, like a team's, and for the same structural reason: the palette is
 * the organization's, and a second settable palette is a second set of contrast
 * pairs nobody validated.
 *
 * What is different is where the mark is allowed to reach. A department's or a
 * team's mark identifies a part of the product; an event's mark, on an install
 * locked to that event, replaces the product's own mark — the header, the
 * favicon, and the desktop window icon. That is deliberate. Most staff working
 * an event were recruited by the event and may never have heard of the company
 * producing it; showing them only the producer's mark asks them to recognise a
 * brand they have no reason to know, on the app they were told to use for the
 * event.
 *
 * It reaches no further than that. An install not locked to an event carries
 * the organization's identity, because outside an event that is the only true
 * answer to whose system this is (BRAND-002).
 */
final class EventBranding
{
    private function __construct(
        public readonly string $eventId,
        public readonly string $name,
        public readonly ?string $logoAttachmentId,
    ) {}

    public static function forEvent(Event $event): self
    {
        return new self(
            eventId: (string) $event->id,
            name: (string) $event->name,
            logoAttachmentId: $event->branding_logo_attachment_id !== null
                ? (string) $event->branding_logo_attachment_id
                : null,
        );
    }

    /**
     * The letters shown when the event has no logo.
     *
     * Note that a consumer choosing chrome should not reach for this: an event
     * with no mark falls back to the *organization's* mark, not to the event's
     * initials. A lettermark here is for surfaces that name the event itself.
     */
    public function lettermark(): string
    {
        return Lettermark::forName($this->name);
    }

    public function hasLogo(): bool
    {
        return $this->logoAttachmentId !== null;
    }
}
