<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Orchid Platform already claimed the physical `attachments` table name for
     * admin uploads. Rename that table so the domain `attachments` table from
     * data/API specification section 10.17 can own the documented name
     * (technical spec 18.5).
     */
    public function up(): void
    {
        Schema::table('attachmentable', function (Blueprint $table) {
            $table->dropForeign(['attachment_id']);
        });

        Schema::rename('attachments', 'orchid_attachments');

        Schema::table('attachmentable', function (Blueprint $table) {
            $table->foreign('attachment_id')
                ->references('id')
                ->on('orchid_attachments')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('attachable_type');
            $table->uuid('attachable_id');
            $table->foreignUuid('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('byte_size');
            $table->string('storage_disk', 64);
            $table->string('storage_path');
            $table->string('checksum', 64);
            $table->json('metadata_json')->nullable();
            $table->foreignUuid('origin_device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('origin_node_id')->constrained('nodes')->restrictOnDelete();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('stricken_at')->nullable();
            // deleted_at is reserved for future policy; Field Report photos are
            // immutable and not deleted in Alpha 1 (technical spec 18.4).
            $table->timestamp('deleted_at')->nullable();

            $table->index(['attachable_type', 'attachable_id']);
            $table->unique(['attachable_type', 'attachable_id', 'filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');

        Schema::table('attachmentable', function (Blueprint $table) {
            $table->dropForeign(['attachment_id']);
        });

        Schema::rename('orchid_attachments', 'attachments');

        Schema::table('attachmentable', function (Blueprint $table) {
            $table->foreign('attachment_id')
                ->references('id')
                ->on('attachments')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }
};
