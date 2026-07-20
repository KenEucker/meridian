<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Incident foreign key after the Incident tables exist.
     */
    public function up(): void
    {
        Schema::table('name_reference_tokens', function (Blueprint $table) {
            $table->foreign('incident_id')
                ->references('id')
                ->on('incidents')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('name_reference_tokens', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
        });
    }
};
