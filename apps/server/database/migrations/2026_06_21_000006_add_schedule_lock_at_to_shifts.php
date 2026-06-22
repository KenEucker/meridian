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
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('schedule_lock_at')->nullable()->after('signup_closes_at');

            $table->index(['event_id', 'schedule_lock_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'schedule_lock_at']);
            $table->dropColumn('schedule_lock_at');
        });
    }
};
