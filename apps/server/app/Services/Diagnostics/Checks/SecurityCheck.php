<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\EventMode\EventModeGuard;
use App\Services\Secrets\SecretSafeguard;

/**
 * Sanitized security warnings (SYS-033: security category). This is a small
 * set of configuration-level warnings, not a vulnerability scanner.
 */
class SecurityCheck implements DiagnosticCheck
{
    public function __construct(
        private readonly EventModeGuard $eventMode,
        private readonly SecretSafeguard $secrets,
    ) {}

    public function key(): string
    {
        return 'security.configuration';
    }

    public function label(): string
    {
        return 'Security configuration';
    }

    public function category(): string
    {
        return DiagnosticCategory::SECURITY;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $environment = (string) app()->environment();
        $eventMode = $this->eventMode->isEventMode();
        $local = in_array($environment, ['local', 'development', 'testing'], true);

        $critical = [];
        $warnings = [];

        if (blank(config('app.key'))) {
            $critical[] = 'The application key is missing.';
        }

        // Default and missing secrets (technical spec 26.2). Critical in event
        // mode because they are what the node refuses to boot on; a warning
        // outside it, because a development machine running the sample database
        // password is doing exactly what the sample is for. Reasons name
        // variables and never values, so the sanitized export stays sanitized.
        foreach ($this->secrets->evaluate()->failures() as $failure) {
            if ($failure->requirement->name === 'APP_KEY' && blank(config('app.key'))) {
                // Already stated above; saying it twice reads as two faults.
                continue;
            }

            if ($eventMode) {
                $critical[] = (string) $failure->reason;
            } else {
                $warnings[] = (string) $failure->reason;
            }
        }

        if ((bool) config('app.debug') && ! $local) {
            $critical[] = 'Debug mode is enabled outside local development.';
        }

        $appUrl = (string) config('app.url');

        if ($eventMode && ! str_starts_with($appUrl, 'https://')) {
            $critical[] = 'The application URL is not HTTPS while this node is in event mode.';
        }

        if (! $eventMode && ! $local && ! str_starts_with($appUrl, 'https://')) {
            $warnings[] = 'The application URL is not HTTPS.';
        }

        $details = [
            'environment' => $environment,
            'event_mode' => $eventMode,
            'app_url_scheme' => parse_url($appUrl, PHP_URL_SCHEME) ?: null,
            'critical_findings' => count($critical),
            'warning_findings' => count($warnings),
        ];

        if ($critical !== []) {
            return DiagnosticResult::critical(
                implode(' ', array_merge($critical, $warnings)),
                $details,
                'Correct the flagged configuration before serving an event with this node.',
            );
        }

        if ($warnings !== []) {
            return DiagnosticResult::warning(implode(' ', $warnings), $details);
        }

        return DiagnosticResult::healthy('No security configuration warnings.', $details);
    }
}
