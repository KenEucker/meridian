<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Attendance is operation-based and append-only, with derived current
     * records for fast roster display (data/API specification section 10.10;
     * technical spec section 20).
     */
    public function up(): void
    {
        Schema::create('attendance_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operation_uuid')->unique();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('shift_assignment_id')->nullable()->constrained('shift_assignments')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('operation_type', 64);
            $table->timestamp('device_created_at');
            $table->timestamp('server_received_at');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('origin_device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('origin_node_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->string('source_context', 64);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['event_id', 'department_id']);
            $table->index(['shift_id', 'staff_id']);
            $table->index(['staff_id', 'operation_type']);
            $table->index(['created_by_user_id', 'created_at']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('shift_assignment_id')->nullable()->constrained('shift_assignments')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('current_state', 64);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'staff_id']);
            $table->index(['event_id', 'department_id']);
            $table->index(['current_state', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_operations');
    }
};
