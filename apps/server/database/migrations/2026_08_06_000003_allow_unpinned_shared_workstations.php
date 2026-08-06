<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a shared workstation exist with no pinned Kiosk context (M18.32; UI-019,
 * UI-020; technical spec 13.1).
 *
 * "If Meridian Kiosk starts without a pinned organization and event, it enters
 * setup" describes a state the table could not hold: `organization_id` and
 * `event_id` were created NOT NULL in M16.8, so every workstation row was pinned
 * by construction and {@see \App\Models\SharedWorkstation::hasPinnedKioskContext()}
 * could only ever answer true. The setup state was reachable in the requirements
 * and unreachable in the schema, which is the same shape of gap M18.31 closed by
 * adding the Placement column a rule had to read.
 *
 * The two unique indexes are left alone. Both include `event_id`, and a null in
 * a unique index is distinct from every other null in both PostgreSQL and
 * SQLite, so an unpinned workstation constrains nothing and stops constraining
 * nothing the moment it is pinned — which is the behaviour a machine waiting to
 * be set up should have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_workstations', function (Blueprint $table): void {
            $table->uuid('organization_id')->nullable()->change();
            $table->uuid('event_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shared_workstations', function (Blueprint $table): void {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->uuid('event_id')->nullable(false)->change();
        });
    }
};
