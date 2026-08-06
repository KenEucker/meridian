<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditPartitioning;
use Illuminate\Console\Command;

/**
 * Create the upcoming monthly partitions for `audit_events` (data/API 14.1).
 *
 * A range-partitioned table refuses an insert with no partition to put it in,
 * and an audit write that fails is a change nobody recorded — so this runs
 * daily and creates months ahead rather than waiting for the first row of a new
 * month to arrive. It is idempotent, so the schedule can run it against a
 * database that is already covered.
 *
 * A no-op where partitioning is unsupported or switched off, which includes
 * every SQLite deployment.
 */
class EnsureAuditPartitionsCommand extends Command
{
    protected $signature = 'meridian:ensure-audit-partitions';

    protected $description = 'Create the upcoming monthly partitions for the audit table';

    public function handle(AuditPartitioning $partitioning): int
    {
        if (! $partitioning->isPartitioned()) {
            $this->info('The audit table is not partitioned on this node; nothing to create.');

            return self::SUCCESS;
        }

        $created = $partitioning->ensurePartitions();

        $this->info(sprintf(
            'Audit partitions present through %s (%d checked).',
            (string) (end($created) ?: 'none'),
            count($created),
        ));

        return self::SUCCESS;
    }
}
