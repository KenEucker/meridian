<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the two audit surfaces that read `audit_events` by scope
 * (M18.29, M18.34; data/API 14.1).
 *
 * The original table indexed `created_at`, `(entity_type, entity_id)`, and
 * `action` — the columns the write path and the per-entity lookup use. Nothing
 * read the table by organization or department until the product audit review
 * and the God Mode trail arrived, and both filter on exactly those columns.
 *
 * PostgreSQL does not index a foreign key column for you. It creates an index
 * for a primary key and for a unique constraint; a foreign key gets a
 * constraint and nothing else, which is the difference from MySQL that makes
 * this easy to miss. `foreignUuid()->constrained()` therefore left
 * `organization_id`, `department_id`, `event_id`, and `actor_user_id`
 * unindexed, and every scoped audit read was a sequential scan.
 *
 * `(organization_id, created_at)` is the composite both surfaces actually want:
 * the product read filters by organization and orders by time, and the God Mode
 * trail does the same whenever its organization filter is set. The standalone
 * `organization_id` index is deliberately *not* added beside it, because a
 * composite index serves a leading-column lookup on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            // The shape both audit surfaces read in: one organization's
            // history, newest first.
            $table->index(['organization_id', 'created_at'], 'audit_events_organization_created_index');

            // Department narrowing on both surfaces, and the event column,
            // which the trail carries and nothing had indexed.
            $table->index('department_id', 'audit_events_department_id_index');
            $table->index('event_id', 'audit_events_event_id_index');

            // "What did this person do", which is the question an operator
            // opens the trail with second most often after "what just happened".
            $table->index('actor_user_id', 'audit_events_actor_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_organization_created_index');
            $table->dropIndex('audit_events_department_id_index');
            $table->dropIndex('audit_events_event_id_index');
            $table->dropIndex('audit_events_actor_user_id_index');
        });
    }
};
