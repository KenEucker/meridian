<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Actual hours are distinct from scheduled shifts and are created from
     * checkout (requirements HOURS-001 through HOURS-006; data/API section
     * 10.10).
     */
    public function up(): void
    {
        Schema::create('hours_worked', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('attendance_record_id')->unique()->constrained('attendance_records')->restrictOnDelete();
            $table->timestamp('actual_started_at');
            $table->timestamp('actual_ended_at');
            $table->unsignedInteger('minutes_worked');
            $table->string('status', 64);
            $table->foreignUuid('corrected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('server_corrected_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'department_id']);
            $table->index(['shift_id', 'staff_id']);
            $table->index(['status', 'actual_ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hours_worked');
    }
};
