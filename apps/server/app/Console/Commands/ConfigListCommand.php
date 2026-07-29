<?php

namespace App\Console\Commands;

use App\Services\Node\NodeSetupService;
use App\Services\SystemConfig\SystemConfigResolver;
use Illuminate\Console\Command;

/**
 * List the configuration catalogue with effective sources (technical spec
 * 22A.12). Applies the same masking as the screen: secret values never print
 * (SYS-013).
 */
class ConfigListCommand extends Command
{
    protected $signature = 'meridian:config:list {--json : Emit the catalogue as JSON}';

    protected $description = 'List catalogued environment variables with their effective sources and override state';

    public function handle(NodeSetupService $nodes, SystemConfigResolver $resolver): int
    {
        $values = $resolver->valuesFor($nodes->activeNode());

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(static fn ($value): array => [
                'name' => $value->entry->name,
                'section' => $value->entry->section,
                'value' => $value->displayValue,
                'source' => $value->source,
                'secret' => $value->entry->secret,
                'editable' => $value->entry->editable(),
                'override_exists' => $value->override !== null,
                'override_active' => (bool) $value->override?->is_active,
                'override_pending' => $value->overridePending,
                'activation' => $value->entry->activation(),
                'validation_error' => $value->validationError,
            ], $values), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Variable', 'Value', 'Source', 'Override', 'Activation'],
            array_map(static fn ($value): array => [
                $value->entry->name,
                $value->displayValue,
                $value->sourceLabel(),
                $value->override === null
                    ? '—'
                    : (($value->override->is_active ? 'active' : 'disabled').($value->overridePending ? ' (pending)' : '')),
                $value->entry->activation(),
            ], $values),
        );

        return self::SUCCESS;
    }
}
