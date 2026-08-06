<?php

use App\Services\Audit\AuditPartitioning;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild `audit_events` as a monthly range-partitioned table (data/API 14.1).
 *
 * PostgreSQL cannot convert an ordinary table into a partitioned one in place,
 * so this is the documented dance: build the partitioned table beside the old
 * one, create the partitions the existing rows need, copy, swap the names, drop
 * the original. It runs in the migration's transaction, so a failure at any
 * point leaves the original table exactly as it was.
 *
 * Skipped entirely when the driver is not PostgreSQL — SQLite, which the test
 * suite runs on, has no declarative partitioning — and when partitioning is
 * switched off in configuration. In both cases the ordinary table stays, and
 * every audit read and write behaves identically; that is the property the
 * tests assert.
 *
 * Two schema changes come with partitioning and neither is cosmetic:
 *
 *  - The primary key becomes `(id, created_at)`, because Postgres requires the
 *    partition key in every unique constraint. `id` remains unique in practice
 *    — it is a UUID — but the database no longer enforces it across partitions.
 *  - `created_at` becomes not-null, because a partition key cannot be null. Any
 *    existing null is filled with the epoch rather than dropped: a row that
 *    never recorded its own timestamp is still a row that happened.
 *
 * Foreign keys are recreated on the partitioned table with the same
 * `restrict on delete` behaviour, so the original guarantee holds — audit
 * history still prevents the silent destruction of the actors and scopes it
 * names.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partitioning = app(AuditPartitioning::class);

        if (! $partitioning->isEnabled() || ! $partitioning->tableExists() || $partitioning->isPartitioned()) {
            return;
        }

        DB::statement('alter table audit_events alter column created_at set default now()');
        DB::statement("update audit_events set created_at = '1970-01-01 00:00:00' where created_at is null");

        DB::statement('
            create table audit_events_partitioned (
                id uuid not null,
                organization_id uuid null references organizations(id) on delete restrict,
                event_id uuid null references events(id) on delete restrict,
                department_id uuid null references departments(id) on delete restrict,
                actor_user_id uuid null references users(id) on delete restrict,
                actor_device_id uuid null references devices(id) on delete restrict,
                actor_node_id uuid null references nodes(id) on delete restrict,
                action varchar(255) not null,
                entity_type varchar(255) not null,
                entity_id uuid not null,
                before_json json null,
                after_json json null,
                reason text null,
                source_context varchar(255) not null,
                signature_metadata_json json null,
                created_at timestamp(0) without time zone not null,
                primary key (id, created_at)
            ) partition by range (created_at)
        ');

        /*
         * The catch-all first, so the copy below cannot fail on a row whose
         * month the loop missed — a clock that was wrong once is enough.
         */
        DB::statement('create table if not exists audit_events_default partition of audit_events_partitioned default');

        $this->createPartitionsCoveringExistingRows();

        DB::statement('insert into audit_events_partitioned select
            id, organization_id, event_id, department_id, actor_user_id, actor_device_id, actor_node_id,
            action, entity_type, entity_id, before_json, after_json, reason, source_context,
            signature_metadata_json, created_at
            from audit_events');

        DB::statement('drop table audit_events');
        DB::statement('alter table audit_events_partitioned rename to audit_events');

        // The same indexes the ordinary table carried. Declared on the parent,
        // so Postgres creates and maintains a matching index on every partition
        // including the ones the scheduled command adds later.
        DB::statement('create index audit_events_created_at_index on audit_events (created_at)');
        DB::statement('create index audit_events_entity_type_entity_id_index on audit_events (entity_type, entity_id)');
        DB::statement('create index audit_events_action_index on audit_events (action)');
        DB::statement('create index audit_events_organization_created_index on audit_events (organization_id, created_at)');
        DB::statement('create index audit_events_department_id_index on audit_events (department_id)');
        DB::statement('create index audit_events_event_id_index on audit_events (event_id)');
        DB::statement('create index audit_events_actor_user_id_index on audit_events (actor_user_id)');

        app(AuditPartitioning::class)->ensurePartitions();
    }

    /**
     * A partition for every month the existing rows fall in.
     *
     * The copy below fails on the first row with nowhere to go, so this has to
     * be exhaustive rather than approximate — which is why it reads the range
     * out of the data rather than assuming the table is recent.
     */
    private function createPartitionsCoveringExistingRows(): void
    {
        $range = DB::selectOne('select min(created_at) as oldest, max(created_at) as newest from audit_events');

        if (($range->oldest ?? null) === null) {
            return;
        }

        $month = \Carbon\CarbonImmutable::parse($range->oldest)->startOfMonth();
        $last = \Carbon\CarbonImmutable::parse($range->newest)->startOfMonth();

        while ($month->lessThanOrEqualTo($last)) {
            DB::statement(sprintf(
                "create table if not exists audit_events_%s partition of audit_events_partitioned for values from ('%s') to ('%s')",
                $month->format('Y_m'),
                $month->toDateTimeString(),
                $month->addMonth()->toDateTimeString(),
            ));

            $month = $month->addMonth();
        }
    }

    /**
     * Collapse the partitions back into one ordinary table.
     *
     * Rolling back must not lose rows, so this copies before it drops, in the
     * mirror of `up()`.
     */
    public function down(): void
    {
        $partitioning = app(AuditPartitioning::class);

        if (! $partitioning->isSupported() || ! $partitioning->isPartitioned()) {
            return;
        }

        DB::statement('create table audit_events_flat (like audit_events including defaults)');
        DB::statement('insert into audit_events_flat select * from audit_events');
        DB::statement('drop table audit_events cascade');
        DB::statement('alter table audit_events_flat rename to audit_events');
        DB::statement('alter table audit_events add primary key (id)');

        Schema::table('audit_events', function ($table): void {
            $table->index('created_at');
            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index(['organization_id', 'created_at'], 'audit_events_organization_created_index');
            $table->index('department_id', 'audit_events_department_id_index');
            $table->index('event_id', 'audit_events_event_id_index');
            $table->index('actor_user_id', 'audit_events_actor_user_id_index');
        });
    }
};
