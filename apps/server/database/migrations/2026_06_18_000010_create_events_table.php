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
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->dateTimeTz('starts_at')->nullable()->index();
            $table->dateTimeTz('ends_at')->nullable()->index();
            $table->string('timezone');
            $table->string('status', 64)->nullable()->index();
            $table->uuid('ic_department_id')->nullable()->index();
            $table->dateTimeTz('active_event_window_starts_at')->nullable()->index();
            $table->dateTimeTz('active_event_window_ends_at')->nullable()->index();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['organization_id', 'starts_at']);
            $table->index(['organization_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
