<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Incident timeline entries are append-only operational history for IMS
     * records (INC-007, INC-014; technical spec 19.7; data/API 10.16).
     */
    public function up(): void
    {
        Schema::create('incident_timeline_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('entry_type', 64)->index();
            $table->text('body')->nullable();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->dateTimeTz('created_at')->index();
            $table->dateTimeTz('stricken_at')->nullable()->index();
            $table->text('stricken_reason')->nullable();

            $table->index(['incident_id', 'created_at']);
            $table->index(['incident_id', 'entry_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_timeline_entries');
    }
};
