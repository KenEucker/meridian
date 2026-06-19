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
        Schema::create('policy_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->string('title');
            $table->string('slug');
            $table->longText('markdown_source');
            $table->string('state')->default('draft');
            $table->unsignedInteger('document_revision')->default(1);
            $table->unsignedInteger('fragment_revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'state']);
            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id', 'state']);
            $table->index('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
    }
};
