<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Event Horizon's whole schema footprint (M18.38, M18.38A, M18.44;
 * HORIZON-011 through HORIZON-015; data/API 10.1, 10.21).
 *
 * `event_horizon_lead_days` is the HORIZON-011 lead-up window: how many days
 * before the event's active window start the Event Horizon begins to be
 * presented. The default is 30, which HORIZON-011 names as the documented
 * default, and the column is non-nullable for the same reason the grace period
 * above it is — an organization that never opens the configuration surface
 * still has a window. It is held in days rather than as a date so that moving
 * an event's dates moves the window with it (technical spec 21D.4), on the
 * same reasoning as the SHIFT-017 relative schedule cutoff.
 *
 * `event_horizon_dismissals` is one staff member having hidden the Event
 * Horizon for one event (HORIZON-012), and it is the only row the feature
 * owns: no table stores a compiled item, an outstanding count, or a readiness
 * state, because the view compiles on read (technical spec 21D.3). One row per
 * staff member per event; restoring the surface deletes the row rather than
 * adding a second state, because "not hidden" is the absence of a decision and
 * needs no record. No `hidden_by_user_id` is stored: a staff member is the
 * only person who can create or remove their own row, so the column would
 * record the same fact as `staff_id` and imply that someone else could.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('event_horizon_lead_days')->default(30);
        });

        Schema::create('event_horizon_dismissals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(['staff_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_horizon_dismissals');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('event_horizon_lead_days');
        });
    }
};
