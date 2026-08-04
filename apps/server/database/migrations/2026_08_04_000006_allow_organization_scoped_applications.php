<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization-scoped applications (M18.21A; APP-001, APP-016, APP-018).
 *
 * `event_applications.event_id` becomes nullable, and a null event is the
 * whole of what an organization-scoped application is: somebody offering to
 * join the organization without naming an event.
 *
 * One nullable column rather than a second table, because the two intakes
 * differ in what the application is *about* and in nothing else. They share the
 * status set (APP-003), the reviewers (APP-005), the Do Not Staff
 * auto-rejection (STAT-006), applicant-only withdrawal (APP-004), and the
 * approval outcome — approval has always created organization-level Prospective
 * status (APP-006), so an application with no event already lands exactly where
 * an approved event application lands. A separate table would have duplicated
 * that entire machine to express one absent foreign key.
 *
 * The table keeps its name. `event_applications` is what every index, foreign
 * key, factory, and query in the codebase calls it, and renaming a table to
 * improve an adjective would cost more than the adjective is worth.
 *
 * `organizations.accepts_organization_applications` is the APP-018 switch, and
 * it defaults to false. An organization already running events recruits into
 * them; an open-ended intake queue nobody agreed to review is a queue that goes
 * unread, so this is opt-in rather than something that appears on a public page
 * the day the migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_applications', function (Blueprint $table): void {
            $table->uuid('event_id')->nullable()->change();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('accepts_organization_applications')
                ->default(false)
                ->after('notifications_suppressed_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('accepts_organization_applications');
        });

        // Organization-scoped applications have no event to fall back to, so
        // they are removed rather than pointed at an arbitrary one. Reversing
        // this migration is reversing the feature.
        DB::table('event_applications')->whereNull('event_id')->delete();

        Schema::table('event_applications', function (Blueprint $table): void {
            $table->uuid('event_id')->nullable(false)->change();
        });
    }
};
