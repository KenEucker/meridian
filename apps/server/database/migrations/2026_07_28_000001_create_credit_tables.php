<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit policies and the credit ledger (requirements ORG-009, ORG-010,
     * SHIFT-010, CREDIT-001 through CREDIT-005; data/API section 10.12).
     *
     * A ledger entry is the frozen result of a calculation, not a running
     * total. Credits are calculated from hours the correction grace period has
     * already closed on (CREDIT-001) and freeze at that moment (CREDIT-004), so
     * the row is written once and never edited: `credit_ledger_entries` records
     * a creation timestamp and no `updated_at`, in the same append-only shape
     * `audit_events` and `waiver_completions` use.
     */
    public function up(): void
    {
        Schema::create('credit_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->string('name');
            // Credits per hour worked. Three decimal places so a half-credit or
            // a 1.25x hardship multiplier is expressible without rounding the
            // policy itself.
            $table->decimal('credit_multiplier', 8, 3);
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['organization_id', 'archived_at']);
            $table->index('event_id');
            $table->index('shift_id');
        });

        // The organization default (ORG-009). The column has existed since the
        // organizations table was created; the table it points at exists only
        // now. There is no department default, because ORG-010 says there is
        // not one.
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('default_credit_policy_id')
                ->references('id')
                ->on('credit_policies')
                ->restrictOnDelete();
        });

        // The shift override (SHIFT-010; data/API section 10.9). Nullable
        // because most shifts do not define one and fall back to the
        // organization default (CREDIT-003).
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignUuid('credit_policy_id')
                ->nullable()
                ->after('schedule_lock_at')
                ->constrained('credit_policies')
                ->restrictOnDelete();
        });

        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('hours_worked_id')->constrained('hours_worked')->restrictOnDelete();
            $table->foreignUuid('credit_policy_id')->constrained('credit_policies')->restrictOnDelete();
            $table->string('entry_type', 32);
            $table->decimal('hours', 10, 2);
            $table->decimal('credits', 10, 2);
            $table->string('status', 32);
            // What the number was derived from, so a reader can re-check the
            // arithmetic without the policy record (CREDIT-005).
            $table->json('calculation_basis');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('frozen_at')->nullable();

            // One calculated entry per hours record is what makes recalculation
            // safe to run twice: the second run finds the entry and leaves it
            // alone rather than repricing finished work. A later adjustment
            // entry type that needs more than one row per record owns changing
            // this index.
            $table->unique(['hours_worked_id', 'entry_type']);
            $table->index(['event_id', 'department_id']);
            $table->index(['event_id', 'staff_id']);
            $table->index(['staff_id', 'frozen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['credit_policy_id']);
            $table->dropColumn('credit_policy_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['default_credit_policy_id']);
        });

        Schema::dropIfExists('credit_policies');
    }
};
