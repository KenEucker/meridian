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
 * Primary database availability and latency (SYS-033: database category).
 * Reports engine and version; never credentials or connection strings.
 */
class DatabaseCheck implements DiagnosticCheck
{
    public function key(): string
    {
        return 'database.connection';
    }

    public function label(): string
    {
        return 'Database connection';
    }

    public function category(): string
    {
        return DiagnosticCategory::DATABASE;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $connection = (string) config('database.default');

        try {
            $start = hrtime(true);
            DB::connection()->select('select 1');
            $latencyMs = (hrtime(true) - $start) / 1_000_000;
        } catch (Throwable $exception) {
            return DiagnosticResult::critical(
                'The primary database is unreachable.',
                ['connection' => $connection, 'exception' => $exception::class],
                'Verify the database service is running and the node\'s database configuration is correct.',
            );
        }

        $details = [
            'connection' => $connection,
            'driver' => (string) DB::connection()->getDriverName(),
            'query_latency_ms' => round($latencyMs, 2),
            'server_version' => $this->serverVersion(),
            'migrations_table' => $this->hasMigrationsTable(),
        ];

        if ($details['migrations_table'] === false) {
            return DiagnosticResult::warning(
                'The database answers but the migrations table is missing.',
                $details,
                'Run php artisan migrate.',
            );
        }

        if ($latencyMs > 250) {
            return DiagnosticResult::warning(
                sprintf('The database answers slowly (%.0f ms).', $latencyMs),
                $details,
            );
        }

        return DiagnosticResult::healthy('The database answers normally.', $details);
    }

    private function serverVersion(): ?string
    {
        try {
            return (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return null;
        }
    }

    private function hasMigrationsTable(): ?bool
    {
        try {
            return Schema::hasTable('migrations');
        } catch (Throwable) {
            return null;
        }
    }
}
