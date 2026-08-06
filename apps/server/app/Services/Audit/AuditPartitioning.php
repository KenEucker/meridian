<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly declarative partitioning for `audit_events` (data/API 14.1).
 *
 * The table is append-only and grows for the life of a deployment. Every scoped
 * read either audit surface makes is time-ordered, so partitioning by month on
 * `created_at` lets PostgreSQL prune to the partitions a page actually touches,
 * and lets a month's history be detached and archived later without a delete
 * ever running against live rows.
 *
 * Three facts about Postgres shape this and are worth stating rather than
 * discovering:
 *
 *  - **The partition key has to be in every unique constraint**, including the
 *    primary key. So the partitioned table's key is `(id, created_at)` rather
 *    than `id`. `id` stays unique in practice because it is a UUID; what is
 *    lost is the database enforcing that across partitions, which is the price
 *    of the feature.
 *  - **`created_at` cannot be null** on a partition key column. The original
 *    table allows null; the rebuild fills any null with the epoch and makes the
 *    column not-null, because a row that never recorded when it happened cannot
 *    be assigned to a month.
 *  - **A row with no partition to land in is an insert that fails.** That is
 *    why {@see ensurePartitions()} runs daily and creates months ahead rather
 *    than on demand.
 *
 * SQLite has no declarative partitioning, and the test suite runs on SQLite, so
 * every method here answers "not applicable" rather than failing. That is not a
 * gap in coverage: what the tests can and do assert is that the application
 * behaves identically either way, which is the property that matters.
 */
final class AuditPartitioning
{
    public const TABLE = 'audit_events';

    public function isSupported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function isEnabled(): bool
    {
        return $this->isSupported()
            && (bool) config('meridian.audit.partitioning.enabled', true);
    }

    /**
     * Whether the live table is already partitioned.
     */
    public function isPartitioned(): bool
    {
        if (! $this->isSupported()) {
            return false;
        }

        $result = DB::selectOne(
            "select relkind from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [self::TABLE],
        );

        // 'p' is a partitioned table; 'r' is an ordinary one.
        return ($result->relkind ?? null) === 'p';
    }

    /**
     * Create the partitions covering now through `months_ahead`, plus the
     * month just gone, and return the names created.
     *
     * Idempotent by `if not exists`, so the daily schedule is free to run it
     * against a database that is already covered.
     *
     * @return list<string>
     */
    public function ensurePartitions(?CarbonImmutable $from = null): array
    {
        if (! $this->isPartitioned()) {
            return [];
        }

        $start = ($from ?? CarbonImmutable::now())->startOfMonth();
        $monthsAhead = max(1, (int) config('meridian.audit.partitioning.months_ahead', 3));
        $created = [];

        // One month back as well as forward: a node whose clock or whose
        // backlog is behind can still be writing last month's rows.
        for ($offset = -1; $offset <= $monthsAhead; $offset++) {
            $month = $start->addMonths($offset);
            $created[] = $this->createPartition($month);
        }

        $created[] = $this->ensureDefaultPartition();

        return $created;
    }

    /**
     * The partitions that exist, oldest first, with the month each covers.
     *
     * Read by the God Mode screen, so an operator can see that the schedule is
     * keeping up rather than finding out when an insert fails.
     *
     * @return list<array{name: string, bounds: string}>
     */
    public function partitions(): array
    {
        if (! $this->isPartitioned()) {
            return [];
        }

        $rows = DB::select(
            'select c.relname as name, pg_get_expr(c.relpartbound, c.oid) as bounds
             from pg_class c
             join pg_inherits i on i.inhrelid = c.oid
             join pg_class parent on parent.oid = i.inhparent
             where parent.relname = ?
             order by c.relname',
            [self::TABLE],
        );

        return array_map(static fn ($row): array => [
            'name' => (string) $row->name,
            'bounds' => (string) $row->bounds,
        ], $rows);
    }

    /**
     * The catch-all partition, for a row whose timestamp falls outside every
     * month that has one.
     *
     * Without it a partitioned table refuses such a row outright, and an audit
     * write that fails is a change nobody recorded — which is the one failure
     * mode this whole feature must not introduce. The cases are real: a
     * backdated correction, a node whose clock was wrong, a backlog syncing
     * history older than the oldest partition. A default partition turns every
     * one of them from a lost record into a row in a table an operator can go
     * and look at.
     *
     * The cost is that attaching a new month later has to check the default
     * partition for rows belonging to it. That is a slow operation on a large
     * default partition, and it is the right trade against losing writes.
     */
    private function ensureDefaultPartition(): string
    {
        $name = self::TABLE.'_default';

        DB::statement(sprintf(
            'create table if not exists %s partition of %s default',
            $name,
            self::TABLE,
        ));

        return $name;
    }

    private function createPartition(CarbonImmutable $month): string
    {
        $name = sprintf('%s_%s', self::TABLE, $month->format('Y_m'));

        DB::statement(sprintf(
            'create table if not exists %s partition of %s for values from (%s) to (%s)',
            $name,
            self::TABLE,
            $this->quote($month->startOfMonth()->toDateTimeString()),
            $this->quote($month->addMonth()->startOfMonth()->toDateTimeString()),
        ));

        return $name;
    }

    /**
     * Values are month boundaries this class computed, never anything a caller
     * supplied, so there is nothing here for a parameter to protect against —
     * but `create table ... partition of` takes no bind parameters, so the
     * quoting is done rather than assumed.
     */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Whether the table exists at all, for a migration running against a
     * database that has not created it yet.
     */
    public function tableExists(): bool
    {
        return Schema::hasTable(self::TABLE);
    }
}
