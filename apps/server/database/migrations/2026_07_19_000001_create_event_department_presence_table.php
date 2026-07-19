<?php

use App\Models\EventDepartmentPresence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Department presence is separate from shift attendance: Logistics marks an
     * eligible department staff member on-site before that staff member can be
     * added to or checked in for a live shift.
     */
    public function up(): void
    {
        Schema::create('event_department_presences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('current_state', 32)->default(EventDepartmentPresence::STATE_OFF_SITE)->index();
            $table->timestamp('marked_on_site_at')->nullable();
            $table->timestamp('marked_off_site_at')->nullable();
            $table->foreignUuid('last_marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'department_id', 'staff_id']);
            $table->index(['event_id', 'department_id', 'current_state']);
            $table->index(['staff_id', 'current_state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_department_presences');
    }
};
