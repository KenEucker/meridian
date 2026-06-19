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
        Schema::create('event_application_department_interests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_application_id')->constrained('event_applications')->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['event_application_id', 'department_id'],
                'event_application_department_interest_unique',
            );
            $table->index(['department_id', 'event_application_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_application_department_interests');
    }
};
