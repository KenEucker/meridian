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
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('signup_opens_at')->nullable()->after('capacity');
            $table->timestamp('signup_closes_at')->nullable()->after('signup_opens_at');

            $table->index(['event_id', 'signup_opens_at']);
            $table->index(['event_id', 'signup_closes_at']);
        });

        Schema::create('shift_training_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['shift_id', 'training_id']);
            $table->index('training_id');
        });

        Schema::create('shift_waiver_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignUuid('waiver_id')->constrained('waivers')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['shift_id', 'waiver_id']);
            $table->index('waiver_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_waiver_requirements');
        Schema::dropIfExists('shift_training_requirements');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'signup_opens_at']);
            $table->dropIndex(['event_id', 'signup_closes_at']);
            $table->dropColumn(['signup_opens_at', 'signup_closes_at']);
        });
    }
};
