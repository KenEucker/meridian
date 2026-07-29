<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\Diagnostics\DiagnosticStatus;

/**
 * Configured external integrations (SYS-033: integrations category).
 *
 * Checks run only for integrations that are actually configured, are
 * presence/completeness checks, and never send a message, place a call, or
 * create an external resource (SYS-032). An install with no integrations
 * configured reports not-applicable, not failure.
 */
class IntegrationsCheck implements DiagnosticCheck
{
    public function key(): string
    {
        return 'integrations.configured';
    }

    public function label(): string
    {
        return 'External integrations';
    }

    public function category(): string
    {
        return DiagnosticCategory::INTEGRATIONS;
    }

    public function required(): bool
    {
        return false;
    }

    public function run(): DiagnosticResult
    {
        $details = [];
        $problems = [];
        $configuredCount = 0;

        // Mail is always configured to something; only non-local transports
        // are treated as an external integration.
        $mailer = (string) config('mail.default');
        $details['mail_transport'] = $mailer;

        if (! in_array($mailer, ['log', 'array', 'failover'], true)) {
            $configuredCount++;

            if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
                $problems[] = 'SMTP mail is selected but no host is configured.';
            }
        }

        foreach (['google', 'discord'] as $provider) {
            $id = config("meridian.oauth.{$provider}.client_id");
            $secret = config("meridian.oauth.{$provider}.client_secret");
            $state = match (true) {
                filled($id) && filled($secret) => 'configured',
                filled($id) || filled($secret) => 'incomplete',
                default => 'not configured',
            };

            $details["{$provider}_oauth"] = $state;

            if ($state === 'incomplete') {
                $problems[] = ucfirst($provider).' OAuth is missing its client ID or secret.';
            }

            if ($state === 'configured') {
                $configuredCount++;
            }
        }

        $bucket = config('filesystems.disks.s3.bucket');
        $details['object_storage'] = filled($bucket) ? 'configured' : 'not configured';

        if (filled($bucket)) {
            $configuredCount++;

            if (blank(config('filesystems.disks.s3.key')) || blank(config('filesystems.disks.s3.secret'))) {
                $problems[] = 'Object storage names a bucket but is missing credentials.';
            }
        }

        $details['changelog_refresh'] = (bool) config('meridian.changelog.refresh.enabled') ? 'enabled' : 'disabled';

        if ($problems !== []) {
            return DiagnosticResult::warning(
                implode(' ', $problems),
                $details,
                'Complete or remove the partially configured integration.',
            );
        }

        if ($configuredCount === 0) {
            return new DiagnosticResult(
                DiagnosticStatus::NOT_APPLICABLE,
                'No external integrations are configured on this install.',
                $details,
            );
        }

        return DiagnosticResult::healthy("{$configuredCount} integration(s) configured completely.", $details);
    }
}
