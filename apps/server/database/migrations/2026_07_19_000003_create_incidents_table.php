<?php

use App\Models\Incident;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Incidents are event-specific, online-only IMS records with server-assigned
     * human-facing numbers (INC-001, INC-003, INC-004; data/API 4.4 and 10.16).
     * Timeline entries, field-report links, involved staff, types, tags, and
     * attachments are owned by later M11 tasks.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->string('incident_number');
            $table->string('status', 32)->default(Incident::STATUS_OPEN)->index();
            $table->dateTimeTz('started_at')->index();
            $table->string('title', 200);
            $table->string('location_name')->nullable();
            $table->string('location_address')->nullable();
            $table->text('location_details')->nullable();
            $table->uuid('camp_id')->nullable()->index();
            $table->uuid('map_location_id')->nullable()->index();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->dateTimeTz('closed_at')->nullable()->index();

            $table->unique(['event_id', 'incident_number']);
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
