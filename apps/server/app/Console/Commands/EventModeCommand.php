<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EventMode\EventModeCheck;
use App\Services\EventMode\EventModeGuard;
use Illuminate\Console\Command;

/**
 * The event-mode fail-closed checks, as a command (technical spec 8.2, 8.6,
 * 26.2): HTTPS validation, offline read set availability, and configured
 * secrets, evaluated exactly as boot enforcement evaluates them.
 *
 * Exit code is the deployment-tooling contract, the same one `meridian:secrets`
 * holds: non-zero means this node refuses to serve as configured. The
 * deployment entrypoint runs it after the config and route caches are built, so
 * what is validated is the cached configuration and route set the served
 * requests will actually read — and a failing node stops at start with one
 * legible line in `docker compose logs` instead of coming up and answering 503
 * to everything.
 *
 * In development mode no check applies and the command succeeds, because a
 * laptop on plain HTTP is not a broken deployment.
 */
class EventModeCommand extends Command
{
    protected $signature = 'meridian:event-mode
        {--json : Emit the evaluation as JSON}';

    protected $description = 'Report the event-mode fail-closed checks; exits non-zero when this node refuses to serve';

    public function handle(EventModeGuard $guard): int
    {
        $readiness = $guard->evaluate();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $readiness->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $readiness->blocked() ? self::FAILURE : self::SUCCESS;
        }

        if (! $readiness->eventMode) {
            $this->line('This node is in development mode, so no event-mode fail-closed check applies (technical spec 26.1). A node in an event or production role is refused service when one fails.');

            return self::SUCCESS;
        }

        $this->table(
            ['Check', 'State'],
            array_map(
                static fn (EventModeCheck $check): array => [
                    $check->label,
                    $check->passed ? 'ok' : 'failed',
                ],
                $readiness->checks,
            ),
        );

        if (! $readiness->blocked()) {
            $this->info('Every event-mode fail-closed check passes.');

            return self::SUCCESS;
        }

        foreach ($readiness->reasons() as $reason) {
            $this->error($reason);
        }

        $this->error('This node is in event mode and refuses to serve until the findings above are resolved (technical spec 8.6, 26.2).');

        return self::FAILURE;
    }
}
