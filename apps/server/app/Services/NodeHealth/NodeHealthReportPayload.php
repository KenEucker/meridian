<?php

declare(strict_types=1);

namespace App\Services\NodeHealth;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The signed wire form of one sanitized node health report (technical spec
 * 22A.11; SYS-037 through SYS-039).
 *
 * The signature covers the canonical payload — a format marker, a newline,
 * and the report fields JSON-encoded in fixed order — signed with the
 * reporting node's private key and verified against the public key central
 * learned at pairing. This mirrors the node sync exchange envelope; nothing
 * unauthenticated in a report is ever stored.
 *
 * What a report may carry is a closed list: identity, versions, statuses,
 * numeric summaries, and sanitized warning lines. Environment values,
 * secrets, credentials, and operational or volunteer data have no field to
 * ride in (SYS-039).
 */
final class NodeHealthReportPayload
{
    public const CANONICAL_FORMAT = 'meridian.node-health-report.v1';

    /**
     * @param  array<string, string>  $categoryStatuses
     * @param  array<string, int|float|string|null>  $summary
     * @param  list<array{key: string, status: string, summary: string}>  $warnings
     */
    private function __construct(
        public readonly string $sourceNodeId,
        public readonly string $reportUuid,
        public readonly string $overallStatus,
        public readonly string $nodeName,
        public readonly string $nodeRole,
        public readonly string $meridianVersion,
        public readonly int $configSchemaVersion,
        public readonly array $categoryStatuses,
        public readonly array $summary,
        public readonly array $warnings,
        public readonly CarbonImmutable $generatedAt,
        public readonly string $signature,
    ) {}

    /**
     * @param  array<string, string>  $categoryStatuses
     * @param  array<string, int|float|string|null>  $summary
     * @param  list<array{key: string, status: string, summary: string}>  $warnings
     */
    public static function create(
        string $sourceNodeId,
        string $reportUuid,
        string $overallStatus,
        string $nodeName,
        string $nodeRole,
        string $meridianVersion,
        int $configSchemaVersion,
        array $categoryStatuses,
        array $summary,
        array $warnings,
        CarbonImmutable $generatedAt,
    ): self {
        return new self(
            sourceNodeId: $sourceNodeId,
            reportUuid: $reportUuid,
            overallStatus: $overallStatus,
            nodeName: $nodeName,
            nodeRole: $nodeRole,
            meridianVersion: $meridianVersion,
            configSchemaVersion: $configSchemaVersion,
            categoryStatuses: $categoryStatuses,
            summary: $summary,
            warnings: $warnings,
            generatedAt: $generatedAt,
            signature: '',
        );
    }

    public function signedWith(string $signature): self
    {
        return new self(
            sourceNodeId: $this->sourceNodeId,
            reportUuid: $this->reportUuid,
            overallStatus: $this->overallStatus,
            nodeName: $this->nodeName,
            nodeRole: $this->nodeRole,
            meridianVersion: $this->meridianVersion,
            configSchemaVersion: $this->configSchemaVersion,
            categoryStatuses: $this->categoryStatuses,
            summary: $this->summary,
            warnings: $this->warnings,
            generatedAt: $this->generatedAt,
            signature: $signature,
        );
    }

    /**
     * The exact bytes the signature covers. Key order is fixed by
     * construction and nested maps are key-sorted, so two nodes that never
     * share code paths agree on the bytes.
     */
    public function canonicalPayload(): string
    {
        $categoryStatuses = $this->categoryStatuses;
        ksort($categoryStatuses);

        $summary = $this->summary;
        ksort($summary);

        $json = json_encode([
            'source_node_id' => $this->sourceNodeId,
            'report_uuid' => $this->reportUuid,
            'overall_status' => $this->overallStatus,
            'node_name' => $this->nodeName,
            'node_role' => $this->nodeRole,
            'meridian_version' => $this->meridianVersion,
            'config_schema_version' => $this->configSchemaVersion,
            'category_statuses' => $categoryStatuses,
            'summary' => $summary,
            'warnings' => $this->warnings,
            'generated_at' => $this->generatedAt->utc()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::CANONICAL_FORMAT."\n".$json;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_node_id' => $this->sourceNodeId,
            'report_uuid' => $this->reportUuid,
            'overall_status' => $this->overallStatus,
            'node_name' => $this->nodeName,
            'node_role' => $this->nodeRole,
            'meridian_version' => $this->meridianVersion,
            'config_schema_version' => $this->configSchemaVersion,
            'category_statuses' => $this->categoryStatuses,
            'summary' => $this->summary,
            'warnings' => $this->warnings,
            'generated_at' => $this->generatedAt->utc()->toIso8601String(),
            'signature' => $this->signature,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeHealthException
     */
    public static function fromArray(array $data): self
    {
        foreach ([
            'source_node_id', 'report_uuid', 'overall_status', 'node_name', 'node_role',
            'meridian_version', 'generated_at', 'signature',
        ] as $field) {
            if (! is_string($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                throw NodeHealthException::missingField($field);
            }
        }

        if (! is_int($data['config_schema_version'] ?? null)) {
            throw NodeHealthException::missingField('config_schema_version');
        }

        try {
            $generatedAt = CarbonImmutable::parse((string) $data['generated_at'])->utc();
        } catch (Throwable) {
            throw NodeHealthException::missingField('generated_at');
        }

        $warnings = [];

        foreach (is_array($data['warnings'] ?? null) ? $data['warnings'] : [] as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $warnings[] = [
                'key' => (string) ($warning['key'] ?? ''),
                'status' => (string) ($warning['status'] ?? ''),
                'summary' => (string) ($warning['summary'] ?? ''),
            ];
        }

        return new self(
            sourceNodeId: trim((string) $data['source_node_id']),
            reportUuid: trim((string) $data['report_uuid']),
            overallStatus: trim((string) $data['overall_status']),
            nodeName: trim((string) $data['node_name']),
            nodeRole: trim((string) $data['node_role']),
            meridianVersion: trim((string) $data['meridian_version']),
            configSchemaVersion: (int) $data['config_schema_version'],
            categoryStatuses: self::stringMap($data['category_statuses'] ?? []),
            summary: self::scalarMap($data['summary'] ?? []),
            warnings: $warnings,
            generatedAt: $generatedAt,
            signature: trim((string) $data['signature']),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private static function scalarMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $map[$key] = is_bool($item) ? ($item ? 'true' : 'false') : $item;
            }
        }

        return $map;
    }
}
