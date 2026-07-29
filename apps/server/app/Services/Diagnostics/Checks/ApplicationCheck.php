<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use Illuminate\Foundation\Application;
use Throwable;

/**
 * Application runtime and build state (SYS-033: application category).
 */
class ApplicationCheck implements DiagnosticCheck
{
    public function __construct(private readonly Application $app) {}

    public function key(): string
    {
        return 'application.runtime';
    }

    public function label(): string
    {
        return 'Application runtime';
    }

    public function category(): string
    {
        return DiagnosticCategory::APPLICATION;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $environment = (string) $this->app->environment();
        $debug = (bool) config('app.debug');
        $maintenance = $this->app->isDownForMaintenance();

        $details = [
            'meridian_version' => (string) config('meridian.version'),
            'config_schema_version' => (int) config('meridian.config_schema_version'),
            'laravel_version' => $this->app->version(),
            'php_version' => PHP_VERSION,
            'environment' => $environment,
            'debug' => $debug,
            'maintenance_mode' => $maintenance,
            'timezone' => (string) config('app.timezone'),
            'configuration_cached' => $this->app->configurationIsCached(),
            'routes_cached' => $this->app->routesAreCached(),
            'events_cached' => $this->app->eventsAreCached(),
        ];

        $warnings = [];

        if ($debug && ! in_array($environment, ['local', 'development', 'testing'], true)) {
            $warnings[] = 'Debug mode is enabled outside local development.';
        }

        if ($maintenance) {
            $warnings[] = 'The application is in maintenance mode.';
        }

        $pending = $this->pendingMigrations();

        if ($pending === null) {
            $details['pending_migrations'] = null;

            $warnings[] = 'Migration state could not be determined.';
        } else {
            $details['pending_migrations'] = $pending;

            if ($pending > 0) {
                $warnings[] = "{$pending} migration(s) have not been run.";
            }
        }

        if ($warnings !== []) {
            return DiagnosticResult::warning(
                implode(' ', $warnings),
                $details,
                $pending !== null && $pending > 0 ? 'Run php artisan migrate.' : null,
            );
        }

        return DiagnosticResult::healthy('Runtime and build state look normal.', $details);
    }

    private function pendingMigrations(): ?int
    {
        try {
            /** @var \Illuminate\Database\Migrations\Migrator $migrator */
            $migrator = $this->app->make('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles($this->app->databasePath('migrations'));

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }
}
