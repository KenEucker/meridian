<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('expires_after_days')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['organization_id', 'archived_at']);
            $table->index(['department_id', 'archived_at']);
            $table->index(['event_id', 'archived_at']);
        });

        Schema::create('training_prerequisites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignUuid('prerequisite_training_id')->constrained('trainings')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['training_id', 'prerequisite_training_id']);
            $table->index('prerequisite_training_id');
        });

        Schema::create('training_completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('training_id')->constrained('trainings')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('origin_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['training_id', 'staff_id']);
            $table->index(['staff_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_completions');
        Schema::dropIfExists('training_prerequisites');
        Schema::dropIfExists('trainings');
    }
};
