<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A schedule lock/cutoff may be expressed relative to the event's active
     * event window as well as by an absolute timestamp (SHIFT-017): the offset
     * counts backward from the window start, so a cutoff configured once stays
     * correct when event dates move. A shift carries one form or the other,
     * never both.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedInteger('schedule_lock_offset_minutes')
                ->nullable()
                ->after('schedule_lock_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('schedule_lock_offset_minutes');
        });
    }
};
