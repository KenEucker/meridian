<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One organization's state for one module in the fixed catalogue
     * (MOD-005; data/API 10.1A; M19.11).
     *
     * `entitled` is the platform's decision (MOD-006) and `enabled` is the
     * organization's (MOD-008). They are two columns rather than one effective
     * flag on purpose: revoking entitlement must not destroy the organization's
     * own choice, so `enabled` survives a revoke and is honored again when
     * entitlement returns (MOD-007). Active is `entitled AND enabled` and is
     * computed, never stored.
     *
     * There is no `modules` table. The catalogue is Meridian's own build-time
     * constant ({@see \App\Domain\Modules\ModuleKey}), because a database row
     * must never be able to invent a module the code does not implement
     * (MOD-003). `module_key` is therefore validated against the enum on write
     * rather than by a foreign key.
     *
     * A missing row reads as entitled and enabled. That makes an organization
     * created before this table, and a module added to the catalogue in a later
     * build, both default to available rather than silently absent — the same
     * direction as MOD-004's promise that a forgotten declaration leaves
     * capability reachable. M19.19 writes rows for every module at organization
     * creation, which turns the missing-row case into the migration and upgrade
     * safety net it is meant to be rather than a normal state.
     *
     * The `*_changed_at` / `*_changed_by_user_id` pairs record the last
     * transition of each state for display. They do not replace the audit
     * trail: every transition also writes an audit event with previous and new
     * state, actor, and any supplied reason (MOD-011), which lands with the
     * screens that make transitions in M19.13 and M19.15.
     */
    public function up(): void
    {
        Schema::create('organization_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('module_key');
            $table->boolean('entitled');
            $table->boolean('enabled');
            $table->timestamp('entitlement_changed_at')->nullable();
            $table->foreignUuid('entitlement_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enablement_changed_at')->nullable();
            $table->foreignUuid('enablement_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_modules');
    }
};
