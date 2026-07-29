<?php

namespace App\Console\Commands;

use App\Services\Node\NodeSetupService;
use App\Services\SystemConfig\SystemConfigResolver;
use Illuminate\Console\Command;

/**
 * Validate stored overrides and required variables (technical spec 22A.12).
 *
 * Exits non-zero when a stored override fails typed validation or a required
 * variable resolves to missing, so deployment tooling can catch a broken
 * configuration before it serves an event.
 */
class ConfigValidateCommand extends Command
{
    protected $signature = 'meridian:config:validate';

    protected $description = 'Validate stored configuration overrides and required variables';

    public function handle(NodeSetupService $nodes, SystemConfigResolver $resolver): int
    {
        $problems = 0;

        foreach ($resolver->valuesFor($nodes->activeNode()) as $value) {
            if ($value->validationError !== null) {
                $this->error("{$value->entry->name}: {$value->validationError}");
                $problems++;
            }

            if ($value->entry->required && $value->source === 'missing') {
                $this->error("{$value->entry->name}: required but not configured.");
                $problems++;
            }
        }

        if ($problems === 0) {
            $this->info('All stored overrides are valid and all required variables are configured.');

            return self::SUCCESS;
        }

        $this->warn("{$problems} problem(s) found.");

        return self::FAILURE;
    }
}
