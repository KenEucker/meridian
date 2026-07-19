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
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('default_ic_department_id')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreign('ic_department_id')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['ic_department_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['default_ic_department_id']);
        });
    }
};
