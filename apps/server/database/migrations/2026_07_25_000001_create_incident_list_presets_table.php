<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * M11.19 adds per-user saved IMS incident list filter presets.
     *
     * A preset is personal view state, not an operational record: it stores one
     * named search/filter/sort selection for one user on one event. It carries
     * no incident content and grants no access, so reading a preset still runs
     * the normal IC gate before any incident row is returned.
     */
    public function up(): void
    {
        Schema::create('incident_list_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('filters');
            $table->dateTimeTz('created_at');
            $table->dateTimeTz('updated_at');

            $table->unique(['event_id', 'user_id', 'name']);
            $table->index(['event_id', 'user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_list_presets');
    }
};
