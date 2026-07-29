<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * The result of one import run: every row's outcome plus the counts an operator
 * reads first (technical spec 22.2).
 *
 * `$preview` records whether the run was a dry run. A preview reports exactly
 * what a real run would do, because it is a real run inside a transaction that
 * is rolled back, so the two can never drift apart.
 */
final class ImportResult
{
    /**
     * @param  list<ImportRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $preview = false,
    ) {}

    public function imported(): int
    {
        return $this->countOf(ImportRow::STATUS_IMPORTED);
    }

    public function updated(): int
    {
        return $this->countOf(ImportRow::STATUS_UPDATED);
    }

    public function skipped(): int
    {
        return $this->countOf(ImportRow::STATUS_SKIPPED);
    }

    /**
     * Counts only, for audit payloads and toast messages.
     *
     * @return array{imported: int, updated: int, skipped: int, preview: bool}
     */
    public function summary(): array
    {
        return [
            'imported' => $this->imported(),
            'updated' => $this->updated(),
            'skipped' => $this->skipped(),
            'preview' => $this->preview,
        ];
    }

    /**
     * @return array{
     *     imported: int,
     *     updated: int,
     *     skipped: int,
     *     preview: bool,
     *     rows: list<array{row: int, identifier: string, status: string, reason: string|null}>
     * }
     */
    public function toArray(): array
    {
        return $this->summary() + [
            'rows' => array_map(static fn (ImportRow $row): array => $row->toArray(), $this->rows),
        ];
    }

    private function countOf(string $status): int
    {
        return count(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => $row->status === $status,
        ));
    }
}
