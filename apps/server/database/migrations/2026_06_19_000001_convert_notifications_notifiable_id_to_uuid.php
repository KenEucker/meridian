<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recreate notifications with UUID notifiable_id after users moved to UUID PKs.
     */
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        if (Schema::getColumnType('notifications', 'notifiable_id') === 'uuid') {
            return;
        }

        Schema::drop('notifications');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->uuid('notifiable_id');
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No safe down path after UUID conversion.
    }
};
