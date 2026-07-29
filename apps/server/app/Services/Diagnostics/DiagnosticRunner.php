<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs registered diagnostic checks and produces a report (technical spec
 * 22A.8; SYS-029 through SYS-032).
 *
 * Checks are independent: one throwing does not stop the run. A throwing
 * required check reports critical, an optional one reports unknown, and only
 * the exception class is recorded — exception messages from drivers routinely
 * carry hosts and credentials, and results must stay export-safe.
 */
class DiagnosticRunner
{
    /**
     * @var list<DiagnosticCheck>
     */
    private array $checks = [];

    public function register(DiagnosticCheck $check): static
    {
        $this->checks[] = $check;

        return $this;
    }

    /**
     * @return list<DiagnosticCheck>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    public function run(): DiagnosticsReport
    {
        $completed = [];

        foreach ($this->checks as $check) {
            $completed[] = $this->runOne($check);
        }

        return new DiagnosticsReport($completed, CarbonImmutable::now());
    }

    private function runOne(DiagnosticCheck $check): CompletedDiagnostic
    {
        $start = hrtime(true);

        try {
            $result = $check->run();
        } catch (Throwable $exception) {
            $result = new DiagnosticResult(
                status: $check->required() ? DiagnosticStatus::CRITICAL : DiagnosticStatus::UNKNOWN,
                summary: 'The check itself failed to run.',
                details: ['exception' => $exception::class],
                recommendedAction: 'Check the application log around this time for the underlying error.',
            );
        }

        return new CompletedDiagnostic(
            key: $check->key(),
            label: $check->label(),
            category: $check->category(),
            required: $check->required(),
            result: $result,
            durationMs: (hrtime(true) - $start) / 1_000_000,
            checkedAt: CarbonImmutable::now(),
        );
    }
}
