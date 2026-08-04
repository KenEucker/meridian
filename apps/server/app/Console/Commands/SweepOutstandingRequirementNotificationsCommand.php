<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Node\NodeSetupService;
use App\Services\Notifications\OutstandingRequirementSweep;
use Illuminate\Console\Command;

/**
 * Notify staff of outstanding document acknowledgments and outstanding or
 * expired waivers (M18.21; NOTIFY-001, NOTIFY-008).
 *
 * These are the two notifications in the NOTIFY-001 set that no operation
 * causes — a waiver becomes outstanding because a date passed — so they are
 * swept for daily rather than emitted from a write path.
 *
 * It runs only where sending is owned. An on-site node queues notifications
 * generated during the active event window and hands them to central
 * (NOTIFY-008), but a sweep has no actor to sign an operation on behalf of and
 * describes a state central can read for itself, so on-site declines rather
 * than queueing a duplicate of what central will find anyway.
 */
class SweepOutstandingRequirementNotificationsCommand extends Command
{
    protected $signature = 'meridian:sweep-outstanding-requirement-notifications';

    protected $description = 'Notify staff of outstanding document acknowledgments and outstanding or expired waivers';

    /**
     * @var list<string>
     */
    private const SENDING_ROLES = [
        Node::ROLE_CENTRAL,
        Node::ROLE_STANDALONE,
        Node::ROLE_DEVELOPMENT,
    ];

    public function handle(NodeSetupService $nodes, OutstandingRequirementSweep $sweep): int
    {
        $node = $nodes->activeNode();

        if ($node instanceof Node
            && ! in_array((string) $node->node_role, self::SENDING_ROLES, true)) {
            $this->info('This node does not send notification email; central sweeps for outstanding requirements.');

            return self::SUCCESS;
        }

        $result = $sweep->run();

        $this->info(sprintf(
            'Outstanding requirement notifications: %d acknowledgment, %d waiver.',
            $result['acknowledgments'],
            $result['waivers'],
        ));

        return self::SUCCESS;
    }
}
