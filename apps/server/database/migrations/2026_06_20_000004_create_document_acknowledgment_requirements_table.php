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
        Schema::create('document_acknowledgment_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->string('document_type');
            $table->uuid('document_id');
            $table->string('requirement_context');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->index(['document_type', 'document_id']);
            $table->index(['organization_id', 'requirement_context', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgment_requirements');
    }
};
