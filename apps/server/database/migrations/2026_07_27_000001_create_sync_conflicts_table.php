<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the God-mode sync conflict queue described in data/API
     * specification section 14.2 and technical spec section 10.3. Conflicts are
     * operations that could not be safely applied; they are reviewed in God
     * Mode / Orchid only for Alpha 1, and unresolved conflicts must not block
     * unrelated sync.
     *
     * The primary key is a UUID per the identifier policy (data/API section
     * 4.1, technical spec section 6.2). Foreign keys restrict on delete so a
     * conflict cannot silently lose its operation or reviewer history.
     *
     * Status and resolution enumerations are not named in section 14.2. Alpha 1
     * uses `open` / `resolved` for status and `accept_onsite` /
     * `accept_central` for resolution, matching the documented resolve choices
     * (technical spec 10.3; data/API 7.5). Resolution itself lands with M12.9.
     */
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_id')->constrained('node_operations')->restrictOnDelete();
            $table->string('conflict_type', 64);
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->json('local_value_json')->nullable();
            $table->json('remote_value_json')->nullable();
            $table->text('reason');
            $table->string('status', 32);
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['operation_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
