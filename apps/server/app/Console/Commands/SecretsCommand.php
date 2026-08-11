<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Secrets\SecretGenerationResult;
use App\Services\Secrets\SecretGenerator;
use App\Services\Secrets\SecretSafeguard;
use App\Services\Secrets\SecretStatus;
use Illuminate\Console\Command;

/**
 * The secret half of node setup (technical spec 7.4, 26.2).
 *
 * Technical spec 7.4 gives setup two entrances — `meridian setup` and the
 * first-run web flow — and says both should generate secrets that are missing
 * or still set to defaults. The web flow does that for the node keypair by
 * creating it with the node; this is the command-line side, and the one the
 * deployment entrypoint runs before a container serves anything.
 *
 * Exit code is the deployment-tooling contract: non-zero means this node would
 * refuse to serve as configured. In development the same findings are reported
 * and the command succeeds, because a laptop with the sample database password
 * is not a broken deployment.
 *
 * No secret value is ever printed. The table carries names, states, and what to
 * do; `key:generate --show` remains the explicit way to put one on a screen.
 */
class SecretsCommand extends Command
{
    protected $signature = 'meridian:secrets
        {--generate : Generate the secrets Meridian owns when they are missing or still a sample value}
        {--json : Emit the evaluation as JSON}';

    protected $description = 'Report and optionally generate node secrets; exits non-zero when this node would refuse to serve';

    public function handle(SecretSafeguard $safeguard, SecretGenerator $generator): int
    {
        $generated = [];

        if ($this->option('generate')) {
            $generated = $generator->generate();
        }

        $readiness = $safeguard->evaluate();
        $protected = $safeguard->inProtectedMode();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'protected_mode' => $protected,
                'generated' => array_map(
                    static fn (SecretGenerationResult $result): array => [
                        'name' => $result->name,
                        'outcome' => $result->outcome,
                        'message' => $result->message,
                    ],
                    $generated,
                ),
                ...$readiness->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $readiness->blocked() && $protected ? self::FAILURE : self::SUCCESS;
        }

        foreach ($generated as $result) {
            $line = sprintf('%s: %s', $result->name, $result->message);

            match ($result->outcome) {
                SecretGenerationResult::GENERATED => $this->info($line),
                SecretGenerationResult::MANUAL => $this->warn($line),
                default => $this->line($line),
            };
        }

        $this->table(
            ['Secret', 'Variable', 'State', 'What to do'],
            array_map(
                static fn (SecretStatus $status): array => [
                    $status->requirement->label,
                    $status->requirement->name,
                    $status->state,
                    $status->failed() ? $status->requirement->remedy : '',
                ],
                $readiness->statuses,
            ),
        );

        if (! $readiness->blocked()) {
            $this->info('Every secret this node uses is configured and none is a sample value.');

            return self::SUCCESS;
        }

        foreach ($readiness->reasons() as $reason) {
            // Red only where it stops something. The same finding on a
            // development machine is a note, and colouring it as a failure
            // teaches an operator to ignore the colour.
            $protected ? $this->error($reason) : $this->warn($reason);
        }

        if (! $protected) {
            $this->line('This node is in development mode, so nothing above blocks it. A node in production or event mode refuses to serve with these findings (technical spec 26.2).');

            return self::SUCCESS;
        }

        $this->error('This node is in production or event mode and refuses to serve until the findings above are resolved.');

        return self::FAILURE;
    }
}
