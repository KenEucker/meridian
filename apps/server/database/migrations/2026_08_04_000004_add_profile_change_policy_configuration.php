<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization approval policy for handles and profile pictures
 * (VOL-027, VOL-028, VOL-029; data/API 10.1, 10.4).
 *
 * The three organization columns are nullable rather than defaulted in the
 * schema, so a row written before this migration reads as "unset" and the
 * domain supplies the documented default. Storing the default would make an
 * organization that never chose look identical to one that chose the default
 * deliberately, and a later change of default would silently not reach the
 * first of them.
 *
 * `dismissed_at` on the request row is the other half of VOL-029: a staff
 * member who has read a rejection can clear it from their surface without the
 * row being destroyed, because the audit trail and the handle allowance are
 * both counted from these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('handle_change_policy', 32)->nullable()->after('hours_correction_grace_period_days');
            $table->string('profile_picture_change_policy', 32)->nullable()->after('handle_change_policy');
            // VOL-028: how many handle changes apply without review while the
            // policy allows any. Null reads as the documented default of two.
            $table->unsignedSmallInteger('handle_self_service_change_limit')->nullable()->after('profile_picture_change_policy');
        });

        Schema::table('staff_profile_change_requests', function (Blueprint $table): void {
            $table->timestamp('dismissed_at')->nullable()->after('decision_reason');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'handle_change_policy',
                'profile_picture_change_policy',
                'handle_self_service_change_limit',
            ]);
        });

        Schema::table('staff_profile_change_requests', function (Blueprint $table): void {
            $table->dropColumn('dismissed_at');
        });
    }
};
