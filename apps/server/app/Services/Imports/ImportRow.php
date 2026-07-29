<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * The outcome of one row of a CSV import (technical spec 22.2).
 *
 * `$identifier` is whatever the operator recognizes the row by in their
 * spreadsheet — an email address for users, a department/team code pair for
 * teams — so a skipped row can be found and fixed without counting lines.
 */
final class ImportRow
{
    /**
     * A new record was created from this row.
     */
    public const STATUS_IMPORTED = 'imported';

    /**
     * A record already existed and this row changed it.
     */
    public const STATUS_UPDATED = 'updated';

    /**
     * Nothing was written for this row: it was invalid, it duplicated an
     * earlier row, or the existing record already matched the file.
     */
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public readonly int $row,
        public readonly string $identifier,
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {}

    public static function imported(int $row, string $identifier): self
    {
        return new self($row, $identifier, self::STATUS_IMPORTED);
    }

    public static function updated(int $row, string $identifier): self
    {
        return new self($row, $identifier, self::STATUS_UPDATED);
    }

    public static function skipped(int $row, string $identifier, string $reason): self
    {
        return new self($row, $identifier, self::STATUS_SKIPPED, $reason);
    }

    /**
     * @return array{row: int, identifier: string, status: string, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'identifier' => $this->identifier,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
