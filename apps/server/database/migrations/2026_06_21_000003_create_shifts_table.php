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
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('eligible_team_id')->constrained('teams')->restrictOnDelete();
            $table->string('title');
            $table->string('department_name_snapshot');
            $table->string('team_name_snapshot');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();
            $table->timestamp('cancelled_at')->nullable()->index();

            $table->index(['event_id', 'department_id']);
            $table->index(['event_id', 'starts_at']);
            $table->index(['department_id', 'starts_at']);
            $table->index(['eligible_team_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
