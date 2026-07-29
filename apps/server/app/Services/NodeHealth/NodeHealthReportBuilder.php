<?php

declare(strict_types=1);

namespace App\Services\NodeHealth;

use App\Models\Node;
use App\Services\Diagnostics\CompletedDiagnostic;
use App\Services\Diagnostics\DiagnosticRunner;
use App\Services\Diagnostics\DiagnosticsReport;
use App\Services\Diagnostics\DiagnosticStatus;
use App\Services\Node\NodeSyncHealth;
use Illuminate\Support\Str;

/**
 * Builds this node's sanitized health report from a diagnostics run
 * (technical spec 22A.11; SYS-037 through SYS-039).
 *
 * The report is a whitelist projection: overall status, per-category status,
 * versions, numeric sync/disk summaries, and the key/status/summary line of
 * each non-healthy check. Check details, configuration values, and anything
 * an operator would need clearance to read stay on the node.
 */
class NodeHealthReportBuilder
{
    public function __construct(
        private readonly DiagnosticRunner $runner,
        private readonly NodeSyncHealth $syncHealth,
    ) {}

    public function build(Node $node, ?DiagnosticsReport $report = null): NodeHealthReportPayload
    {
        $report ??= $this->runner->run();
        $sync = $this->syncHealth->describe($node);

        return NodeHealthReportPayload::create(
            sourceNodeId: (string) $node->getKey(),
            reportUuid: (string) Str::uuid(),
            overallStatus: $report->overallStatus(),
            nodeName: (string) $node->node_name,
            nodeRole: (string) $node->node_role,
            meridianVersion: (string) config('meridian.version'),
            configSchemaVersion: (int) config('meridian.config_schema_version'),
            categoryStatuses: $this->categoryStatuses($report),
            summary: [
                'queued_operations' => (int) $sync['queued'],
                'undelivered_operations' => (int) $sync['undelivered'],
                'unapplied_operations' => (int) $sync['unapplied'],
                'last_sent_at' => $sync['last_sent_at'],
                'last_received_at' => $sync['last_received_at'],
                'disk_free_bytes' => $this->detailFrom($report, 'storage.filesystem', 'disk_free_bytes'),
                'disk_total_bytes' => $this->detailFrom($report, 'storage.filesystem', 'disk_total_bytes'),
                'memory_usage_bytes' => $this->detailFrom($report, 'node.identity', 'memory_usage_bytes'),
            ],
            warnings: $this->warnings($report),
            generatedAt: $report->generatedAt,
        );
    }

    /**
     * Worst status per category, aggregated the same way the overall status
     * is.
     *
     * @return array<string, string>
     */
    private function categoryStatuses(DiagnosticsReport $report): array
    {
        $rank = [
            DiagnosticStatus::NOT_APPLICABLE => 0,
            DiagnosticStatus::HEALTHY => 1,
            DiagnosticStatus::UNKNOWN => 2,
            DiagnosticStatus::WARNING => 3,
            DiagnosticStatus::CRITICAL => 4,
        ];

        $statuses = [];

        foreach ($report->byCategory() as $category => $checks) {
            $worst = DiagnosticStatus::NOT_APPLICABLE;

            foreach ($checks as $check) {
                if (($rank[$check->result->status] ?? 0) > ($rank[$worst] ?? 0)) {
                    $worst = $check->result->status;
                }
            }

            $statuses[$category] = $worst;
        }

        return $statuses;
    }

    /**
     * @return list<array{key: string, status: string, summary: string}>
     */
    private function warnings(DiagnosticsReport $report): array
    {
        $warnings = [];

        foreach ($report->checks as $check) {
            if (in_array($check->result->status, [
                DiagnosticStatus::WARNING,
                DiagnosticStatus::CRITICAL,
            ], true)) {
                $warnings[] = [
                    'key' => $check->key,
                    'status' => $check->result->status,
                    // Summaries are sanitized by the DiagnosticResult
                    // contract; details deliberately do not travel (SYS-039).
                    'summary' => Str::limit($check->result->summary, 300),
                ];
            }
        }

        return $warnings;
    }

    private function detailFrom(DiagnosticsReport $report, string $key, string $detail): int|float|string|null
    {
        foreach ($report->checks as $check) {
            if ($check->key === $key) {
                $value = $check->result->details[$detail] ?? null;

                return is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        return null;
    }
}
