<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Scheduler liveness via the heartbeat the scheduler itself writes every
 * minute (SYS-033: queue category). Node sync, credit freezes, and health
 * reporting all ride the scheduler, so a stale heartbeat is the earliest sign
 * a node quietly lost its cron/worker.
 */
class SchedulerHeartbeatCheck implements DiagnosticCheck
{
    public const CACHE_KEY = 'meridian.diagnostics.scheduler-heartbeat';

    private const STALE_AFTER_MINUTES = 5;

    public function key(): string
    {
        return 'queue.scheduler-heartbeat';
    }

    public function label(): string
    {
        return 'Scheduler heartbeat';
    }

    public function category(): string
    {
        return DiagnosticCategory::QUEUE;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        try {
            $lastBeat = Cache::get(self::CACHE_KEY);
        } catch (Throwable $exception) {
            return DiagnosticResult::unknown(
                'The scheduler heartbeat could not be read.',
                ['exception' => $exception::class],
            );
        }

        if (! is_string($lastBeat)) {
            return DiagnosticResult::warning(
                'The scheduler has never recorded a heartbeat.',
                ['last_heartbeat_at' => null],
                'Confirm php artisan schedule:run is executed every minute on this node.',
            );
        }

        $at = CarbonImmutable::parse($lastBeat);
        $ageMinutes = $at->diffInMinutes(CarbonImmutable::now());

        $details = [
            'last_heartbeat_at' => $at->toIso8601String(),
            'age_minutes' => (int) $ageMinutes,
        ];

        if ($ageMinutes > self::STALE_AFTER_MINUTES) {
            return DiagnosticResult::critical(
                sprintf('The scheduler has not run for %d minutes.', (int) $ageMinutes),
                $details,
                'Confirm php artisan schedule:run is executed every minute on this node.',
            );
        }

        return DiagnosticResult::healthy('The scheduler ran recently.', $details);
    }
}
