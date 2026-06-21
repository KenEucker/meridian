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
        Schema::create('document_version_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type');
            $table->uuid('document_id');
            $table->unsignedInteger('document_revision');
            $table->unsignedInteger('fragment_revision');
            $table->longText('markdown_source_snapshot');
            $table->longText('resolved_markdown_snapshot');
            $table->string('snapshot_reason');
            $table->timestamp('created_at')->nullable()->index();

            $table->unique(
                ['document_type', 'document_id', 'document_revision', 'fragment_revision'],
                'document_version_snapshots_document_version_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_version_snapshots');
    }
};
