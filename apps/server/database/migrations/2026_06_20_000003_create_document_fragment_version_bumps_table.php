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
        Schema::create('document_fragment_version_bumps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fragment_id')->constrained('document_fragments')->restrictOnDelete();
            $table->unsignedInteger('fragment_version');
            $table->string('document_type');
            $table->uuid('document_id');
            $table->timestamps();

            $table->unique(
                ['fragment_id', 'fragment_version', 'document_type', 'document_id'],
                'document_fragment_version_bumps_once_per_fragment_version',
            );
            $table->index(['document_type', 'document_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_fragment_version_bumps');
    }
};
