<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only node-to-node sync operations (technical spec 10.1, 10.4;
     * data/API 13.3).
     *
     * Central and on-site sync is operation-based, not table replication, so
     * this table is the durable log both sides read and write. Rows are never
     * deleted and their normalized content is never rewritten; only the
     * delivery lifecycle columns (`sent_at`, `received_at`, `applied_at`,
     * `status`, `failure_reason`, `retry_count`) change after insert.
     *
     * `uuid` is unique because it is the idempotency key: receiving the same
     * operation more than once must be safe (technical spec 10.1), and the
     * database is what makes a duplicate insert fail rather than a race in
     * application code.
     *
     * Data/API 13.3 lists a `created_at` but no `updated_at`. `created_at` is
     * the origin node's creation time and travels with the operation, so it is
     * written explicitly rather than inferred from local insert time.
     *
     * Foreign keys restrict on delete so sync history cannot be silently
     * destroyed by removing a node, user, device, or event. `entity_id` is a
     * plain UUID column rather than a constrained key because it is
     * polymorphic across every synced entity type, matching `audit_events`
     * (data/API 4.1, 14.1).
     */
    public function up(): void
    {
        Schema::create('node_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('origin_node_id')->constrained('nodes')->restrictOnDelete();
            $table->foreignUuid('target_node_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actor_device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->string('operation_type', 64);
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('status', 32);
            $table->text('signature');
            $table->string('hash', 128);
            $table->json('payload_json')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('retry_count')->default(0);

            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['event_id', 'created_at']);
            $table->index(['origin_node_id', 'created_at']);
            $table->index(['target_node_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_operations');
    }
};
