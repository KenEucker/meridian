<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Node\NodeSetupService;
use App\Services\Status\LifecycleThresholdEvaluationService;
use Illuminate\Console\Command;

/**
 * Apply the configured Prospective and Active inactivity thresholds (M18.15;
 * ORG-019; STAT-009 through STAT-011).
 *
 * Runs on the scheduler daily, and only where organization status is owned:
 * staff organization status is governance data central is authoritative for,
 * so an on-site node refuses quietly the same way it refuses configuration
 * edits (ORG-021). A standalone or development node is its own central. The
 * evaluation itself is idempotent — a record transitioned yesterday matches
 * no threshold query today — so scheduling it unconditionally is safe.
 */
class EvaluateLifecycleThresholdsCommand extends Command
{
    protected $signature = 'meridian:evaluate-lifecycle-thresholds';

    protected $description = 'Apply the configured organization staff lifecycle inactivity thresholds';

    /**
     * Node roles that own organization staff status.
     *
     * @var list<string>
     */
    private const AUTHORITATIVE_ROLES = [
        Node::ROLE_CENTRAL,
        Node::ROLE_STANDALONE,
        Node::ROLE_DEVELOPMENT,
    ];

    public function handle(
        NodeSetupService $nodes,
        LifecycleThresholdEvaluationService $evaluation,
    ): int {
        $node = $nodes->activeNode();

        if ($node instanceof Node
            && ! in_array((string) $node->node_role, self::AUTHORITATIVE_ROLES, true)) {
            $this->info('This node does not own organization staff status; nothing to evaluate.');

            return self::SUCCESS;
        }

        $result = $evaluation->evaluate();

        $this->info(sprintf(
            'Lifecycle thresholds evaluated: %d prospective expired, %d active lapsed, %d protected by department work.',
            $result['prospective_expired'],
            $result['active_expired'],
            $result['skipped_for_department_work'],
        ));

        return self::SUCCESS;
    }
}
