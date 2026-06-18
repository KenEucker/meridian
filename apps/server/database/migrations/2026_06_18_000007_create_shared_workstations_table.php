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
        Schema::create('shared_workstations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $table->uuid('event_id')->index();
            $table->string('name');
            $table->boolean('trusted')->default(false)->index();
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->unique(['event_id', 'name']);
            $table->unique(['device_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_workstations');
    }
};
