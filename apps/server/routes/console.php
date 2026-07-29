<?php

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
        \App\Services\Diagnostics\Checks\SchedulerHeartbeatCheck::CACHE_KEY,
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
