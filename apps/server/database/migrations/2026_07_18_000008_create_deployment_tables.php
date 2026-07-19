<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deployments represent the current location options and current staff
     * assignment state for MVP (SLB-009, SLB-010; data/API section 10.14).
     * Movement history is intentionally not modeled for Alpha 1.
     */
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('location_details')->nullable();
            $table->uuid('map_location_id')->nullable()->index();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['event_id', 'department_id']);
            $table->index(['department_id', 'archived_at']);
        });

        Schema::create('current_deployment_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('deployment_id')->constrained('deployments')->restrictOnDelete();
            $table->foreignUuid('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['shift_id', 'staff_id']);
            $table->index(['event_id', 'department_id']);
            $table->index(['deployment_id', 'updated_at']);
            $table->index(['staff_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('current_deployment_assignments');
        Schema::dropIfExists('deployments');
    }
};
