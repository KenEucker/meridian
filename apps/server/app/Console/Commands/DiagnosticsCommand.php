<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\DiagnosticRunner;
use App\Services\Diagnostics\DiagnosticsExport;
use App\Services\Diagnostics\DiagnosticStatus;
use Illuminate\Console\Command;

/**
 * Run the diagnostics suite from the CLI (technical spec 22A.12; SYS-041).
 *
 * Exits non-zero when any required check is critical, so container health
 * checks and deployment tooling can gate on it. Output carries the same
 * sanitized results as the screen and the export — no secrets, ever.
 */
class DiagnosticsCommand extends Command
{
    protected $signature = 'meridian:diagnostics {--json : Emit the sanitized diagnostics bundle as JSON}';

    protected $description = 'Run Meridian system diagnostics; exits non-zero when a required check is critical';

    public function handle(DiagnosticRunner $runner, DiagnosticsExport $export): int
    {
        $report = $runner->run();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $export->build($report),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $report->hasRequiredCritical() ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Status', 'Check', 'Category', 'Required', 'Summary'],
            array_map(static fn ($check): array => [
                DiagnosticStatus::label($check->result->status),
                $check->label,
                $check->category,
                $check->required ? 'yes' : 'no',
                $check->result->summary,
            ], $report->checks),
        );

        $this->line('Overall: '.DiagnosticStatus::label($report->overallStatus()));

        return $report->hasRequiredCritical() ? self::FAILURE : self::SUCCESS;
    }
}
