<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\SystemConfig\ApplySystemConfigOverrides;

/**
 * Whether database configuration overrides loaded at boot (SYS-022). A node
 * that fell back to environment configuration keeps running, but an operator
 * has to know their override is not in effect.
 */
class SystemConfigOverridesCheck implements DiagnosticCheck
{
    public function __construct(private readonly ApplySystemConfigOverrides $applier) {}

    public function key(): string
    {
        return 'configuration.overrides';
    }

    public function label(): string
    {
        return 'Database configuration overrides';
    }

    public function category(): string
    {
        return DiagnosticCategory::CONFIGURATION;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        if ($this->applier->loadFailed()) {
            return DiagnosticResult::critical(
                'Database configuration overrides could not be read at boot; this node is running on environment configuration only.',
                ['exception' => $this->applier->loadFailureReason()],
                'Check database availability; overrides re-apply on the next boot after the table is reachable.',
            );
        }

        $skipped = $this->applier->skipped();
        $details = [
            'applied_overrides' => count($this->applier->appliedNames()),
            'skipped_overrides' => count($skipped),
        ];

        if ($skipped !== []) {
            $names = implode(', ', array_column($skipped, 'name'));

            return DiagnosticResult::warning(
                "Stored overrides were skipped as invalid: {$names}.",
                $details,
                'Open System -> Configuration and repair or remove the invalid overrides.',
            );
        }

        return DiagnosticResult::healthy(
            $details['applied_overrides'] > 0
                ? "{$details['applied_overrides']} database override(s) applied at boot."
                : 'No database overrides are stored on this node.',
            $details,
        );
    }
}
