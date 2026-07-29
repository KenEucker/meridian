<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Queue backlog and failed jobs (SYS-033: queue category).
 *
 * Backlog counts are measurable for the database queue driver Meridian ships
 * with. A reachable queue connection does not prove a worker is running, so
 * this check never claims worker liveness — that is the scheduler heartbeat's
 * neighbouring concern and is otherwise honestly unknown.
 */
class QueueCheck implements DiagnosticCheck
{
    public function key(): string
    {
        return 'queue.backlog';
    }

    public function label(): string
    {
        return 'Queue backlog and failed jobs';
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
        $details = [
            'queue_connection' => (string) config('queue.default'),
            'worker_liveness' => 'unknown: a reachable queue does not prove a worker is running',
        ];

        try {
            $failed = Schema::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : null;
            $oldestFailedAt = $failed !== null && $failed > 0
                ? (string) DB::table('failed_jobs')->min('failed_at')
                : null;
            $pending = config('queue.default') === 'database' && Schema::hasTable('jobs')
                ? (int) DB::table('jobs')->count()
                : null;
        } catch (Throwable $exception) {
            return DiagnosticResult::unknown(
                'Queue state could not be read.',
                $details + ['exception' => $exception::class],
            );
        }

        $details['failed_jobs'] = $failed;
        $details['oldest_failed_at'] = $oldestFailedAt;
        $details['pending_jobs'] = $pending;

        if ($failed !== null && $failed > 0) {
            return DiagnosticResult::warning(
                "{$failed} job(s) have failed.",
                $details,
                'Review failed jobs with php artisan queue:failed and retry or prune them.',
            );
        }

        if ($pending !== null && $pending > 500) {
            return DiagnosticResult::warning(
                "The queue backlog is large ({$pending} pending jobs).",
                $details,
                'Confirm a queue worker is running for this node.',
            );
        }

        return DiagnosticResult::healthy('No failed jobs and no unusual backlog.', $details);
    }
}
