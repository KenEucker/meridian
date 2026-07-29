<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Node-scoped database overrides for catalogued environment variables
     * (technical spec 22A.3, 22A.5; SYS-005 through SYS-007).
     *
     * Overrides are deployment infrastructure: they belong to the node they
     * were written on, are never replicated through PowerSync, and are never
     * carried by node-to-node sync (SYS-011, SYS-012). Non-secret values are
     * stored JSON-encoded so type distinctions survive storage; secret values
     * are stored only in the encrypted column (SYS-013).
     */
    public function up(): void
    {
        Schema::create('system_config_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('node_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->text('value_json')->nullable();
            $table->text('secret_value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('change_reason')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['node_id', 'name']);
            $table->index(['node_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_config_overrides');
    }
};
