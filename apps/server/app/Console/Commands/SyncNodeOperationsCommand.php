<?php

namespace App\Console\Commands;

use App\Services\Node\NodeSyncClient;
use App\Services\Node\NodeSyncException;
use Illuminate\Console\Command;

/**
 * Runs one bidirectional sync with the paired central node (technical spec
 * 10.1, 10.2).
 *
 * On-site pushes changes back to central continuously when internet exists and
 * queues them when it does not, so this is scheduled rather than invoked by a
 * human; it is also runnable by hand for QA and for draining a backlog after an
 * outage.
 *
 * Not every reason a run does not happen is a fault. A node that does not sync
 * with central at all, a node that has not paired yet, and a peer that cannot
 * be reached are all ordinary states of a correctly configured install, and a
 * minutely scheduled command that failed on them would report an outage as a
 * defect and bury the real ones. Those report and exit successfully. A node that
 * is meant to be syncing but cannot — no central URL, plain HTTP in event mode,
 * unusable signing keys, or a peer that refused the exchange — needs a human and
 * exits with a failure.
 */
class SyncNodeOperationsCommand extends Command
{
    protected $signature = 'meridian:node-sync';

    protected $description = 'Exchange node operations with the paired central node in both directions';

    /**
     * Reasons a run did not happen that describe a state rather than a fault.
     *
     * @var list<string>
     */
    private const EXPECTED_REASONS = [
        NodeSyncException::REASON_NODE_NOT_CONFIGURED,
        NodeSyncException::REASON_ROLE_CANNOT_SYNC,
        NodeSyncException::REASON_NOT_PAIRED,
        NodeSyncException::REASON_PEER_UNREACHABLE,
    ];

    public function handle(NodeSyncClient $client): int
    {
        try {
            $run = $client->sync();
        } catch (NodeSyncException $failure) {
            if (! in_array($failure->reason, self::EXPECTED_REASONS, true)) {
                $this->error($failure->getMessage());

                return self::FAILURE;
            }

            $this->warn($failure->reason === NodeSyncException::REASON_PEER_UNREACHABLE
                ? $failure->getMessage().' Operations stay queued and will be sent on a later run.'
                : $failure->getMessage());

            return self::SUCCESS;
        }

        $run->needsAttention()
            ? $this->warn($run->summary())
            : $this->info($run->summary());

        return self::SUCCESS;
    }
}
