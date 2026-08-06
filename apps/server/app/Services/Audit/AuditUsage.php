<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * How much audit history an organization is holding (data/API 14.1).
 *
 * Read by the God Mode configuration screen so an operator setting a limit can
 * see what they are limiting, and by {@see AuditArchivalService} to decide
 * whether a limit has been reached.
 *
 * **On "bytes".** There is no per-organization figure a database can give: the
 * table's size on disk is one number for every organization sharing it, and it
 * includes indexes, dead tuples, and the row overhead the storage engine
 * chooses. What this measures instead is the size of the *content* — the two
 * JSON payloads, the reason, and a fixed allowance for the fixed columns —
 * which is the part an operator can actually influence by changing verbosity,
 * and the part that grows without bound. It is an estimate, and the screen
 * calls it one.
 */
final class AuditUsage
{
    /**
     * A rough per-row allowance for the columns that are not free text: the
     * UUID primary key, six nullable UUID foreign keys, the action and entity
     * type strings, the source context, and the timestamp.
     */
    private const FIXED_ROW_BYTES = 220;

    /**
     * @return array{rows: int, bytes: int, oldest_at: string|null, newest_at: string|null}
     */
    public function forOrganization(Organization $organization): array
    {
        $query = AuditEvent::query()->where('organization_id', $organization->getKey());

        $measured = (clone $query)
            ->selectRaw(sprintf(
                'count(*) as row_count, coalesce(sum(%s), 0) as content_bytes',
                $this->contentLengthExpression(),
            ))
            ->first();

        $rows = (int) ($measured->row_count ?? 0);

        return [
            'rows' => $rows,
            'bytes' => (int) ($measured->content_bytes ?? 0) + ($rows * self::FIXED_ROW_BYTES),
            'oldest_at' => (clone $query)->min('created_at'),
            'newest_at' => (clone $query)->max('created_at'),
        ];
    }

    /**
     * The SQL for one row's free-text content length.
     *
     * PostgreSQL will not apply `length()` to a `json` column and needs the
     * cast; SQLite stores the same column as text and needs no cast and has no
     * `octet_length`. One expression per driver rather than one clever
     * expression that works badly on both.
     */
    private function contentLengthExpression(): string
    {
        $columns = ['before_json', 'after_json', 'reason'];

        $driver = DB::connection()->getDriverName();

        $lengths = array_map(
            fn (string $column): string => $driver === 'pgsql'
                ? sprintf("octet_length(coalesce(%s::text, ''))", $column)
                : sprintf("length(coalesce(%s, ''))", $column),
            $columns,
        );

        return implode(' + ', $lengths);
    }
}
