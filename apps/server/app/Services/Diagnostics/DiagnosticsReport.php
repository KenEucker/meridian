<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Carbon\CarbonImmutable;

/**
 * The result of one full diagnostics run, with the overall node health
 * derived consistently from the individual results (SYS-031).
 */
final class DiagnosticsReport
{
    /**
     * @param  list<CompletedDiagnostic>  $checks
     */
    public function __construct(
        public readonly array $checks,
        public readonly CarbonImmutable $generatedAt,
    ) {}

    /**
     * Overall health: a required critical check makes the node critical; an
     * optional critical or any warning makes it a warning; a required check
     * that could not run degrades to warning; not-applicable results are
     * ignored. One failed optional integration never marks the installation
     * critical (SYS-031).
     */
    public function overallStatus(): string
    {
        $overall = DiagnosticStatus::HEALTHY;

        foreach ($this->checks as $check) {
            $overall = match (true) {
                $check->result->status === DiagnosticStatus::CRITICAL && $check->required => DiagnosticStatus::CRITICAL,
                $overall === DiagnosticStatus::CRITICAL => DiagnosticStatus::CRITICAL,
                $check->result->status === DiagnosticStatus::CRITICAL,
                $check->result->status === DiagnosticStatus::WARNING,
                $check->result->status === DiagnosticStatus::UNKNOWN && $check->required => DiagnosticStatus::WARNING,
                default => $overall,
            };
        }

        return $overall;
    }

    public function hasRequiredCritical(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->required && $check->result->status === DiagnosticStatus::CRITICAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(DiagnosticStatus::ALL, 0);

        foreach ($this->checks as $check) {
            $counts[$check->result->status] = ($counts[$check->result->status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, list<CompletedDiagnostic>>
     */
    public function byCategory(): array
    {
        $grouped = [];

        foreach ($this->checks as $check) {
            $grouped[$check->category][] = $check;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overall_status' => $this->overallStatus(),
            'overall_status_label' => DiagnosticStatus::label($this->overallStatus()),
            'generated_at' => $this->generatedAt->toIso8601String(),
            'status_counts' => $this->statusCounts(),
            'checks' => array_map(
                static fn (CompletedDiagnostic $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }
}
