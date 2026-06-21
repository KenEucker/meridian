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
        Schema::create('document_acknowledgments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->string('document_type');
            $table->uuid('document_id');
            $table->unsignedInteger('document_revision');
            $table->unsignedInteger('fragment_revision');
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->timestamp('acknowledged_at')->index();
            $table->foreignUuid('accepted_by_node_id')->constrained('nodes')->restrictOnDelete();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['document_type', 'document_id']);
            $table->index(['user_id', 'document_type', 'document_id']);
            $table->index(['scope_type', 'scope_id']);
            $table->unique(
                [
                    'user_id',
                    'document_type',
                    'document_id',
                    'document_revision',
                    'fragment_revision',
                    'scope_type',
                    'scope_id',
                ],
                'document_acknowledgments_once_per_user_document_version_scope',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgments');
    }
};
