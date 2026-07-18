<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rebuildable derived Name Reference index for Field Report bodies and
     * append bodies (NR-001 through NR-007, NR-011 through NR-014; technical
     * spec 17.7; data/API 10.16 Name Reference derived index). Exact physical
     * storage is not mandated; this table is the Alpha 1 server representation.
     */
    public function up(): void
    {
        Schema::create('name_reference_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_type');
            $table->uuid('source_id');
            $table->foreignUuid('field_report_id')
                ->constrained('field_reports')
                ->restrictOnDelete();
            $table->string('token');
            $table->string('normalized_token');
            $table->timestamp('created_at')->nullable();

            $table->unique(['source_type', 'source_id', 'normalized_token']);
            $table->index(['normalized_token', 'field_report_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('name_reference_tokens');
    }
};
