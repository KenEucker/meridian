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
        Schema::create('document_fragment_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type');
            $table->uuid('document_id');
            $table->foreignUuid('fragment_id')->constrained('document_fragments')->restrictOnDelete();
            $table->string('token');
            $table->unsignedInteger('fragment_version_at_last_edit');
            $table->timestamps();

            $table->unique(['document_type', 'document_id', 'token']);
            $table->index('fragment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_fragment_references');
    }
};
