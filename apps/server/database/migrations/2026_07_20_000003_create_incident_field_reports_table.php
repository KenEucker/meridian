<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Links Field Reports to incidents for permitted incident display/search.
     * M11.8 owns the link/unlink command workflow and copied-note timeline
     * entries; M11.6A needs the relationship for attached-report Name
     * Reference chips (NR-008 through NR-012; data/API 10.16).
     */
    public function up(): void
    {
        Schema::create('incident_field_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('incidents')->restrictOnDelete();
            $table->foreignUuid('field_report_id')->constrained('field_reports')->restrictOnDelete();
            $table->foreignUuid('linked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTimeTz('linked_at')->index();
            $table->foreignUuid('unlinked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTimeTz('unlinked_at')->nullable()->index();
            $table->text('stricken_reason')->nullable();

            $table->index(['incident_id', 'linked_at']);
            $table->index(['field_report_id', 'linked_at']);
            $table->index(['incident_id', 'field_report_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_field_reports');
    }
};
