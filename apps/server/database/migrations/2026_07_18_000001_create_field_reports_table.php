<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates immutable `field_reports` records per data/API specification
     * section 10.15 and technical spec section 17.3. Field reports are
     * event-specific, author-attributed, finalized on create (no drafts), and
     * have no `updated_at` / soft-delete / stricken columns (FR-007, FR-008).
     *
     * FRA assignment (`fra_number`) and offline create/sync acceptance remain
     * with later M9 tasks; nullable numbering and `sync_status` columns are
     * present so those flows can populate them without schema churn.
     */
    public function up(): void
    {
        Schema::create('field_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignUuid('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('fra_number')->nullable();
            $table->string('temporary_local_number')->nullable();
            $table->text('body');
            $table->timestamp('device_submitted_at');
            $table->timestamp('server_received_at')->nullable();
            $table->foreignUuid('origin_device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('origin_node_id')->constrained('nodes')->restrictOnDelete();
            $table->string('sync_status', 64)->index();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['event_id', 'submitted_by_user_id']);
            $table->index(['event_id', 'staff_id']);
            $table->unique(['event_id', 'fra_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_reports');
    }
};
