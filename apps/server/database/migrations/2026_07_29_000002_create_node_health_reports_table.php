<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sanitized node health summaries (technical spec 22A.11; SYS-037 through
     * SYS-040).
     *
     * One row per known node holding its latest verified report. Reports are
     * summaries by construction — statuses, versions, counts, and sanitized
     * warnings — and never environment values, secrets, credentials, or any
     * operational/volunteer data (SYS-039). Central receives them over the
     * node-signature-authenticated report endpoint; each node also stores its
     * own latest report locally so the screen works offline.
     */
    public function up(): void
    {
        Schema::create('node_health_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('node_id')->constrained()->cascadeOnDelete();
            $table->uuid('report_uuid');
            $table->string('overall_status', 32);
            $table->string('node_name');
            $table->string('node_role', 32);
            $table->string('meridian_version', 64);
            $table->integer('config_schema_version');
            $table->json('category_statuses_json');
            $table->json('summary_json');
            $table->json('warnings_json');
            $table->timestampTz('generated_at');
            $table->timestampTz('received_at');
            $table->timestamps();

            $table->unique('node_id');
            $table->index('report_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_health_reports');
    }
};
