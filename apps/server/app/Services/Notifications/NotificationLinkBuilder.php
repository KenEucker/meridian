<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Where a notification's action link points (NOTIFY-004).
 *
 * The base is node configuration rather than a value baked into the message,
 * because the same notification composed on two nodes must link to whichever
 * of them the recipient actually uses, and because a link frozen at dispatch
 * time would outlive the deployment it was true for.
 *
 * Nothing here signs, scopes, or pre-authenticates the link. NOTIFY-004
 * requires that following it not bypass authentication or authorization, so a
 * notification link is an ordinary client path: the recipient arrives at the
 * login flow if they hold no session, and the node refuses the reads
 * underneath the surface regardless of what was linked.
 */
class NotificationLinkBuilder
{
    public function urlFor(NotificationType $type): string
    {
        return $this->base().$type->actionPath();
    }

    private function base(): string
    {
        $configured = trim((string) config('meridian.notifications.link_base_url', ''));

        if ($configured === '') {
            $configured = (string) config('app.url', 'http://localhost');
        }

        return rtrim($configured, '/');
    }
}
