<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Models\Node;
use App\Services\Node\NodeSetupService;
use App\Services\SystemConfig\ResolvedConfigValue;
use App\Services\SystemConfig\SystemConfigResolver;

/**
 * The sanitized JSON diagnostics bundle (technical spec 22A.10; SYS-034,
 * SYS-035).
 *
 * The bundle carries build/version information, node identity, check results,
 * and configuration-source metadata. For configuration it exports source and
 * status only — never raw values, and never anything about a secret beyond
 * whether one is configured. Check results are included verbatim because the
 * {@see DiagnosticResult} contract already requires sanitized details.
 */
class DiagnosticsExport
{
    public function __construct(
        private readonly DiagnosticRunner $runner,
        private readonly NodeSetupService $nodes,
        private readonly SystemConfigResolver $configResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?DiagnosticsReport $report = null): array
    {
        $report ??= $this->runner->run();
        $node = $this->nodes->activeNode();

        return [
            'meridian' => [
                'version' => (string) config('meridian.version'),
                'config_schema_version' => (int) config('meridian.config_schema_version'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'environment' => (string) app()->environment(),
            ],
            'node' => $node instanceof Node ? [
                'id' => (string) $node->getKey(),
                'name' => $node->node_name,
                'role' => $node->node_role,
                'event_locked' => $node->event_id !== null,
            ] : null,
            'generated_at' => $report->generatedAt->toIso8601String(),
            'diagnostics' => $report->toArray(),
            'configuration' => $this->configurationMetadata($node),
        ];
    }

    /**
     * Source and status metadata for every catalogued variable — no values
     * (SYS-035).
     *
     * @return list<array<string, mixed>>
     */
    private function configurationMetadata(?Node $node): array
    {
        return array_map(static function (ResolvedConfigValue $resolved): array {
            $entry = $resolved->entry;

            return [
                'name' => $entry->name,
                'section' => $entry->section,
                'secret' => $entry->secret,
                'required' => $entry->required,
                'bootstrap_locked' => $entry->bootstrapLocked,
                'editable' => $entry->editable(),
                'source' => $resolved->source,
                'configured' => $resolved->source !== ResolvedConfigValue::SOURCE_MISSING,
                'override_exists' => $resolved->override !== null,
                'override_active' => (bool) $resolved->override?->is_active,
                'override_pending' => $resolved->overridePending,
                'validation_error' => $resolved->validationError,
                'activation' => $entry->activation(),
            ];
        }, $this->configResolver->valuesFor($node));
    }
}
