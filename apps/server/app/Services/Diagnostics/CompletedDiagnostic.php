<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Carbon\CarbonImmutable;

/**
 * One check's run: identity, result, and timing (technical spec 22A.8).
 */
final class CompletedDiagnostic
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $category,
        public readonly bool $required,
        public readonly DiagnosticResult $result,
        public readonly float $durationMs,
        public readonly CarbonImmutable $checkedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category,
            'category_label' => DiagnosticCategory::label($this->category),
            'required' => $this->required,
            'status' => $this->result->status,
            'status_label' => DiagnosticStatus::label($this->result->status),
            'summary' => $this->result->summary,
            'details' => $this->result->details,
            'recommended_action' => $this->result->recommendedAction,
            'duration_ms' => round($this->durationMs, 1),
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }
}
