<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The event Placement department designation (M18.31; PLACE-002, PLACE-003;
 * data/API 10.2).
 *
 * Data/API section 10.2 has listed `placement_department_id` on `events` since
 * the Placement requirements were drafted, beside `ic_department_id`, but no
 * milestone had created it. M18.14 took the organization half of the same pair
 * (`organizations.default_placement_department_id`) for the same reason: the
 * surface it built named the designation and the column did not exist.
 *
 * This half is here because PLACE-003 — "the designated Placement department
 * shall be one of the departments assigned to that event" — is a rule about
 * participation, and M18.31 is where participation is managed. A rule that
 * cannot be checked is not a rule, so the column the check reads arrives with
 * the check.
 *
 * Choosing the designation is not part of this task. PLACE-004 makes the
 * designation unlock map and placement authority, and that authority is
 * M14.5's; a selector offered before it exists would let an organizer grant
 * nothing and be told it worked. What M18.31 adds is the column, the rule, and
 * the refusal that keeps a designated department in the event that designated
 * it.
 *
 * Shaped exactly like `ic_department_id`: a nullable indexed UUID with a
 * restricting foreign key, so a department carrying a designation cannot be
 * deleted out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('placement_department_id')->nullable()->index();

            $table->foreign('placement_department_id')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['placement_department_id']);
            $table->dropColumn('placement_department_id');
        });
    }
};
