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
        Schema::create('department_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('status')->default('active')->index();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->unique(['department_id', 'staff_id']);
            $table->index(['staff_id', 'archived_at']);
            $table->index(['department_id', 'archived_at']);
        });

        Schema::create('team_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('department_membership_id')->constrained()->restrictOnDelete();
            $table->string('membership_role')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->unique(['team_id', 'staff_id']);
            $table->index(['staff_id', 'archived_at']);
            $table->index(['department_membership_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('department_memberships');
    }
};
