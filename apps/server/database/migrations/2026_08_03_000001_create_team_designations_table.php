<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Team designations (TEAM-011 through TEAM-014, TEAM-017; M18.10).
     *
     * A designation is a configuration act that attaches an existing
     * operational grant to a named team: department rows carry the section
     * 4.8A functions (Logistics, Operations, Planning, Administration,
     * Operator) and organization rows carry the Staff Coordinator team inside
     * the configured Organizers Department. Each active designation owns
     * exactly one active `team_grants` row, so authority keeps resolving
     * through team membership and grants (TEAM-010) and a removed designation
     * revokes only the grant it created, never a direct grant (TEAM-013).
     *
     * Removed designations keep their row for history; the service enforces
     * the zero-or-one active designation per function rule (TEAM-012).
     */
    public function up(): void
    {
        Schema::create('team_designations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('function_code');
            $table->foreignUuid('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignUuid('team_grant_id')->constrained('team_grants')->restrictOnDelete();
            $table->timestamps();
            $table->timestamp('removed_at')->nullable()->index();

            $table->index(['department_id', 'function_code', 'removed_at']);
            $table->index(['organization_id', 'function_code', 'removed_at']);
            $table->index(['team_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_designations');
    }
};
