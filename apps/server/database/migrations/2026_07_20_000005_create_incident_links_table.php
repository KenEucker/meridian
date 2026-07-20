<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * M11.7B adds preserved, same-event linked incident relationships
     * (INC-007 through INC-009, INC-014; data/API 10.16).
     */
    public function up(): void
    {
        Schema::create('incident_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignUuid('target_incident_id')->constrained('incidents')->restrictOnDelete();
            $table->string('link_type', 40)->default('related');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTimeTz('created_at')->index();
            $table->foreignUuid('unlinked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTimeTz('unlinked_at')->nullable()->index();

            $table->index(['source_incident_id', 'created_at']);
            $table->index(['target_incident_id', 'created_at']);
            $table->index(['source_incident_id', 'target_incident_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_links');
    }
};
