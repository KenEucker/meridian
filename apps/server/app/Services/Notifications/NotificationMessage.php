<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Branding\BrandingPalette;
use App\Services\Branding\SystemMailIdentity;

/**
 * One composed notification, ready to render (NOTIFY-003, NOTIFY-004).
 *
 * Built at send time and never stored, because NOTIFY-007 keeps message bodies
 * out of the retained trail. It carries the organization's identity — name,
 * mark, palette — because BRAND-002 lists system-generated email among the
 * surfaces organization branding replaces Meridian on, and it carries a
 * single action link because a notification whose recipient has to go and find
 * the screen themselves has told them something without letting them act on it.
 */
final class NotificationMessage
{
    /**
     * @param  list<string>  $paragraphs  Operational prose, one paragraph each.
     * @param  array<string, string>  $facts  Label/value pairs printed above
     *                                        the action, for the specifics a
     *                                        sentence reads badly with.
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly SystemMailIdentity $identity,
        public readonly BrandingPalette $palette,
        public readonly ?string $markUrl,
        public readonly string $lettermark,
        public readonly string $subject,
        public readonly string $heading,
        public readonly array $paragraphs,
        public readonly array $facts,
        public readonly string $actionLabel,
        public readonly string $actionUrl,
    ) {}

    /**
     * The name the message signs itself with: the organization's where it has
     * a branding profile, and Meridian's where it does not (NOTIFY-003).
     */
    public function senderName(): string
    {
        return $this->identity->displayName;
    }
}
