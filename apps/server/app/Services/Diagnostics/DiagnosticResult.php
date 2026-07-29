<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

/**
 * The outcome of one diagnostic check run (technical spec 22A.8; SYS-030).
 *
 * `details` must already be sanitized by the check that produced it: scalar
 * facts safe for screens, exports, and node health reports. No check ever
 * puts credentials, connection strings, tokens, or environment-variable
 * values in here — the export path re-serializes details verbatim and relies
 * on this contract (SYS-034).
 */
final class DiagnosticResult
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly array $details = [],
        public readonly ?string $recommendedAction = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function healthy(string $summary, array $details = []): self
    {
        return new self(DiagnosticStatus::HEALTHY, $summary, $details);
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function warning(string $summary, array $details = [], ?string $recommendedAction = null): self
    {
        return new self(DiagnosticStatus::WARNING, $summary, $details, $recommendedAction);
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function critical(string $summary, array $details = [], ?string $recommendedAction = null): self
    {
        return new self(DiagnosticStatus::CRITICAL, $summary, $details, $recommendedAction);
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    public static function unknown(string $summary, array $details = []): self
    {
        return new self(DiagnosticStatus::UNKNOWN, $summary, $details);
    }

    public static function notApplicable(string $summary): self
    {
        return new self(DiagnosticStatus::NOT_APPLICABLE, $summary);
    }
}
