<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Node;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Services\Node\NodeSetupService;

/**
 * Whether this node may send this notification at all (NOTIFY-008, NOTIFY-009).
 *
 * Three separate questions that all end in nothing arriving, kept separate
 * because they mean different things to whoever is looking:
 *
 *   - **Role.** Only central sends (NOTIFY-008). An on-site node generating a
 *     notification during an event window holds it and hands it to central
 *     through the node sync path; it is not a failure and not a suppression.
 *   - **Global suppression.** The deployment is a test deployment or a restored
 *     backup and must not mail real people (NOTIFY-009).
 *   - **Organization suppression.** One organization has switched its own
 *     sending off, and the organizations beside it on the same node have not.
 *
 * A standalone or development node counts as its own central, which is the same
 * reading the credit calculation and lifecycle threshold commands already take:
 * "central" names where authority lives rather than a deployment topology every
 * install has.
 */
class NotificationSendingPolicy
{
    /**
     * Node roles that own sending. On-site is the only role that does not, and
     * it is the only role NOTIFY-008 actually restricts.
     *
     * @var list<string>
     */
    private const SENDING_ROLES = [
        Node::ROLE_CENTRAL,
        Node::ROLE_STANDALONE,
        Node::ROLE_DEVELOPMENT,
    ];

    public function __construct(private readonly NodeSetupService $nodes) {}

    /**
     * Whether this node is the one that sends, as opposed to the one that
     * queues for whoever does (NOTIFY-008).
     *
     * An install with no configured node has not been told it is on-site, and
     * refusing to send there would make a fresh development machine silently
     * stop mailing with no setting to point at. It is treated as its own
     * central for the same reason a development node is.
     */
    public function nodeMaySend(): bool
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node) {
            return true;
        }

        return in_array((string) $node->node_role, self::SENDING_ROLES, true);
    }

    /** The global development suppression (NOTIFY-009). */
    public function globallySuppressed(): bool
    {
        return (bool) config('meridian.notifications.suppressed', false);
    }

    public function organizationSuppressed(?Organization $organization): bool
    {
        return $organization instanceof Organization && $organization->notificationsSuppressed();
    }

    /**
     * The delivery status this notification resolves to before anything is
     * composed, or null when it may proceed to the queue.
     *
     * Ordered so the answer an operator gets is the most specific true one.
     * A node that does not send has not decided anything about suppression —
     * central will apply its own switches when the operation reaches it — so
     * role is asked first and the suppression questions are central's to ask.
     *
     * @return array{0: string, 1: string}|null status and outcome reason
     */
    public function refusalFor(?Organization $organization): ?array
    {
        if (! $this->nodeMaySend()) {
            return [
                NotificationDelivery::STATUS_HELD_FOR_CENTRAL,
                'This node does not send notification email; central sends it after sync.',
            ];
        }

        if ($this->globallySuppressed()) {
            return [
                NotificationDelivery::STATUS_SUPPRESSED,
                'Notification sending is suppressed on this deployment.',
            ];
        }

        if ($this->organizationSuppressed($organization)) {
            return [
                NotificationDelivery::STATUS_SUPPRESSED,
                'Notification sending is suppressed for this organization.',
            ];
        }

        return null;
    }
}
