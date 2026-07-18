<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds required immutable Field Report titles (FR-003, FR-007; technical
     * spec 17.3/17.4; data/API 10.15). Titles are plain text, trimmed of outer
     * whitespace, 1–200 characters after trimming. Duplicate titles are allowed
     * within an event (no uniqueness constraint).
     */
    public function up(): void
    {
        Schema::table('field_reports', function (Blueprint $table) {
            $table->string('title', 200)->after('temporary_local_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('field_reports', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
