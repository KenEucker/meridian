<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Organization;
use App\Services\Audit\AuditArchivalService;
use App\Services\Node\NodeSetupService;
use Illuminate\Console\Command;

/**
 * Bring every organization within its configured audit limits (data/API 14.1).
 *
 * Runs daily on the scheduler, and only where the audit record is owned. Audit
 * history is written on whichever node the change happened on and syncs to
 * central; archiving it on an on-site node would remove rows central is still
 * expecting, so the same authority rule that governs organization configuration
 * governs this. A standalone or development node is its own central.
 *
 * An organization with no limits configured is skipped without being loaded any
 * further, which is every organization until somebody sets one — the feature
 * costs a `where` clause on a node nobody has configured it on.
 *
 * Each pass archives at most one batch per limit per organization by design.
 * An organization switching a limit on for the first time may be millions of
 * rows over it, and draining that in one statement would hold the table for as
 * long as it took. The next pass measures again and takes the next batch.
 */
class EnforceAuditLimitsCommand extends Command
{
    protected $signature = 'meridian:enforce-audit-limits {--organization= : Limit the pass to one organization id}';

    protected $description = 'Archive audit history past each organization\'s configured limits';

    /**
     * Node roles that own the audit record.
     *
     * @var list<string>
     */
    private const AUTHORITATIVE_ROLES = [
        Node::ROLE_CENTRAL,
        Node::ROLE_STANDALONE,
        Node::ROLE_DEVELOPMENT,
    ];

    public function handle(NodeSetupService $nodes, AuditArchivalService $archival): int
    {
        $node = $nodes->activeNode();

        if ($node instanceof Node
            && ! in_array((string) $node->node_role, self::AUTHORITATIVE_ROLES, true)) {
            $this->info('This node does not own the audit record; nothing to archive here.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->where(fn ($query) => $query
                ->whereNotNull('audit_max_rows')
                ->orWhereNotNull('audit_max_bytes')
                ->orWhereNotNull('audit_retention_days'))
            ->when(
                $this->option('organization') !== null,
                fn ($query) => $query->whereKey((string) $this->option('organization')),
            )
            ->get();

        if ($organizations->isEmpty()) {
            $this->info('No organization has audit limits configured.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($organizations as $organization) {
            $result = $archival->enforce($organization);
            $total += $result['archived'];

            if ($result['archived'] > 0) {
                $this->line(sprintf(
                    '%s: archived %d row(s) to %s (%s).',
                    $organization->name,
                    $result['archived'],
                    $result['file'],
                    implode(', ', $result['reasons']),
                ));
            }
        }

        $this->info(sprintf('Archived %d audit row(s) across %d organization(s).', $total, $organizations->count()));

        return self::SUCCESS;
    }
}
