<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization configuration columns (M18.14; ORG-017, ORG-018; data/API 10.1).
 *
 * `hours_correction_grace_period_days` is the ORG-017 window: days after event
 * end during which authorized attendance managers may correct hours
 * (HOURS-007), after which hours freeze (HOURS-008). The default is 14, which
 * ORG-017 names as the documented default, and the column is non-nullable
 * because a grace period is not optional behavior — an organization that never
 * opens the configuration surface still has one.
 *
 * `default_placement_department_id` completes the ORG-018 designation trio.
 * The data/API specification has listed it on `organizations` since the
 * Placement requirements were drafted, alongside `organizers_department_id`
 * and `default_ic_department_id`, but no milestone had created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('hours_correction_grace_period_days')->default(14);
            $table->uuid('default_placement_department_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['hours_correction_grace_period_days', 'default_placement_department_id']);
        });
    }
};
