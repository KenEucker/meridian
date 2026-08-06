<?php

use App\Services\Diagnostics\Checks\SchedulerHeartbeatCheck;
use App\Services\NameReferences\NameReferenceIndexService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('name-references:rebuild', function (NameReferenceIndexService $index) {
    $count = $index->rebuild();

    $this->info("Rebuilt {$count} Name Reference token(s) from IMS and Field Report source text.");
})->purpose('Rebuild the derived Name Reference index from IMS notes, Field Report bodies, and append bodies');

// On-site pushes changes back to central continuously when internet exists, and
// queues node operations while it does not (technical spec 10.2), so node sync
// runs on a schedule rather than waiting for someone to ask for it. The command
// is a no-op refusal on a node that does not pair with central, so scheduling it
// unconditionally is safe; `withoutOverlapping` keeps a long backlog drain from
// being started again on top of itself.
if ((bool) config('meridian.node.sync.schedule_enabled', true)) {
    Schedule::command('meridian:node-sync')
        ->everyMinute()
        ->withoutOverlapping();
}

// The scheduler heartbeat is how diagnostics can tell a node's cron/worker is
// actually firing (technical spec 22A.8). It is a cache timestamp, written
// every minute, read by the scheduler-heartbeat diagnostic check.
Schedule::call(function (): void {
    Cache::put(
        SchedulerHeartbeatCheck::CACHE_KEY,
        now()->toIso8601String(),
        now()->addHour(),
    );
})->everyMinute()->name('meridian-scheduler-heartbeat');

// Sanitized node health reports ride the signed node sync channel to central
// (technical spec 22A.11). The command refuses quietly on nodes that do not
// report, so scheduling it unconditionally is safe.
Schedule::command('meridian:health-report')
    ->everyTenMinutes()
    ->withoutOverlapping();

// The organization staff lifecycle thresholds are applied on a schedule
// (M18.15; ORG-019): Prospective staff past the configured threshold become
// Inactive (STAT-011), and Active staff past theirs do too, without a person
// performing the transition. The thresholds are measured in years, so daily is
// dense enough; the command refuses quietly on nodes that do not own
// organization status and is idempotent, so scheduling it unconditionally is
// safe.
Schedule::command('meridian:evaluate-lifecycle-thresholds')
    ->daily()
    ->withoutOverlapping();

// Audit limits, where an organization has configured any (data/API 14.1). The
// command archives before it removes and records the archival, so it is safe to
// run unattended; it refuses quietly on a node that does not own the audit
// record, and does nothing at all for an organization with no limits set, which
// is every organization until somebody sets one. Daily rather than hourly
// because a limit is a bound on a table's growth, not a deadline.
Schedule::command('meridian:enforce-audit-limits')
    ->daily()
    ->withoutOverlapping();

// Upcoming monthly audit partitions, where the table is partitioned. A
// range-partitioned table refuses an insert with nowhere to put it, and an
// audit write that fails is a change nobody recorded — so the partitions are
// created months ahead rather than when the first row of a month arrives. A
// no-op on any node whose table is not partitioned, SQLite included.
Schedule::command('meridian:ensure-audit-partitions')
    ->daily()
    ->withoutOverlapping();

// Event credits are written on a schedule once the correction grace period
// closes (M18.16; CREDIT-001), so a configured organization gets its ledger
// without anyone pressing the button on the credit policy surface. The command
// only visits events holding frozen hours no calculated entry covers, refuses
// quietly on nodes that do not own the ledger, and the calculation itself is
// idempotent (CREDIT-004), so scheduling it unconditionally is safe.
Schedule::command('meridian:calculate-event-credits')
    ->daily()
    ->withoutOverlapping();

// The two NOTIFY-001 notifications no operation causes — a required document
// acknowledgment outstanding, and a required waiver outstanding or expired —
// are swept for daily (M18.21). Daily is dense enough for both: a waiver
// expiration is a date, and a published requirement is not urgent the hour it
// appears. The sweep is idempotent through the delivery records themselves and
// refuses quietly on nodes that do not send (NOTIFY-008), so scheduling it
// unconditionally is safe.
Schedule::command('meridian:sweep-outstanding-requirement-notifications')
    ->daily()
    ->withoutOverlapping();
