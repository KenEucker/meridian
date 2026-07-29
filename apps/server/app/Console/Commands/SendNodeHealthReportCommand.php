<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\NodeHealth\NodeHealthException;
use App\Services\NodeHealth\NodeHealthReportBuilder;
use App\Services\NodeHealth\NodeHealthReportClient;
use App\Services\NodeHealth\NodeHealthReportReceiver;
use App\Services\Node\NodeSetupService;
use Illuminate\Console\Command;

/**
 * Build this node's sanitized health report, store it locally, and deliver it
 * to central when this node pairs with one (technical spec 22A.11; SYS-037,
 * SYS-040).
 *
 * Runs on the scheduler every ten minutes. Delivery failure is expected
 * operation for an offline on-site node, so it is reported quietly and the
 * command still succeeds — the local report was stored, which is the part an
 * offline node can do.
 */
class SendNodeHealthReportCommand extends Command
{
    protected $signature = 'meridian:health-report';

    protected $description = 'Generate this node\'s sanitized health report and send it to central when paired';

    public function handle(
        NodeSetupService $nodes,
        NodeHealthReportBuilder $builder,
        NodeHealthReportReceiver $receiver,
        NodeHealthReportClient $client,
    ): int {
        $node = $nodes->activeNode();

        if (! $node instanceof Node) {
            $this->info('No node is configured; nothing to report.');

            return self::SUCCESS;
        }

        $payload = $builder->build($node);
        $receiver->store($node, $payload);

        $this->info("Stored the local health report ({$payload->overallStatus}).");

        if (! $node->canPairWithCentral()) {
            return self::SUCCESS;
        }

        try {
            $client->send($node, $payload);
            $this->info('Delivered the health report to central.');
        } catch (NodeHealthException $exception) {
            // An unreachable central is the expected state for an offline
            // on-site node; the next scheduled run reports again (SYS-036).
            $this->warn("Central did not take the report: {$exception->getMessage()}");
        }

        return self::SUCCESS;
    }
}
