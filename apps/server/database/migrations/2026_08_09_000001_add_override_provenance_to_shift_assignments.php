<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an addition made over a refusal says about itself (M18.55; SLB-008;
 * CLIENT-017A; technical spec 11A.5; data/API 5.6).
 *
 * M18.55 gives a refused Logistics addition a second possible outcome besides
 * dismissal: a caller holding `department.shift_additions.override` re-issues it
 * as a distinct command naming the one that was refused. The whole point of
 * issuing a new command rather than retrying the old one under its own key is
 * that the record then holds *both* facts — the node refused this, and a named
 * person then chose to proceed — instead of collapsing them into an acceptance
 * that reads as though nothing was ever in question.
 *
 * These two columns are where the assignment holds them. `override_of_operation_uuid`
 * names the refused command by the key the device queued it under, which is the
 * only identifier the refusal ever had — the node wrote no row for it, because
 * it refused. `overridden_reason_code` is the one reason that was waived, and
 * one is all an override waives: an addition refused for a second reason after
 * the first is overridden is still refused, and that refusal is its own
 * decision for somebody to make.
 *
 * Both nullable, because every assignment made any other way — a signup, a
 * roster edit, an ordinary addition the node accepted first time — has no
 * refusal behind it, and a placeholder invented for those would make the column
 * mean "there was a refusal" nowhere and "there may have been" everywhere.
 *
 * No unique index on `override_of_operation_uuid`. It names a command that was
 * refused rather than one that was applied, and a refusal overridden, refused
 * again for a second reason, and overridden again is a legitimate sequence that
 * a uniqueness rule would break at the second step. The override command's own
 * key still carries the replay guarantee, on `unscheduled_operation_uuid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table): void {
            $table->uuid('override_of_operation_uuid')->nullable();
            $table->string('overridden_reason_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table): void {
            $table->dropColumn(['override_of_operation_uuid', 'overridden_reason_code']);
        });
    }
};
