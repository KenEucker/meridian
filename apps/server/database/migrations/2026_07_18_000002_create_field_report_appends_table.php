<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates immutable `field_report_appends` records per data/API specification
     * section 10.15 and technical spec section 17.4. Appends are authored only by
     * the original Field Report submitter (FR-009), never edit or strike the
     * original body (FR-007, FR-008), and have no `updated_at` / soft-delete /
     * stricken columns.
     *
     * Photos on appends, Name Reference parsing, HTTP command transport, and
     * incident-note copy of appended content remain with later M9/M11 tasks.
     */
    public function up(): void
    {
        Schema::create('field_report_appends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_report_id')->constrained('field_reports')->restrictOnDelete();
            $table->foreignUuid('appended_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('device_submitted_at');
            $table->timestamp('server_received_at')->nullable();
            $table->foreignUuid('origin_device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('origin_node_id')->constrained('nodes')->restrictOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['field_report_id', 'device_submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_report_appends');
    }
};
