<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the immutable `audit_events` store described in data/API
     * specification section 14.1 and technical spec section 23. The primary key
     * is a UUID per the identifier policy (data/API section 4.1, technical spec
     * section 6.2), which lists audit references among the UUID surfaces.
     *
     * Foreign keys use restrict-on-delete so audit history is preserved and
     * referenced actors/scopes cannot be silently destroyed.
     *
     * All foreign-key columns and the polymorphic `entity_id` are UUIDs: the
     * canonical-key remediation (docs/issues/005-uuid-primary-key-remediation.md)
     * migrated every referenced table to UUID primary keys, so audit references
     * are uniform UUIDs per the identifier policy (data/API section 4.1,
     * technical spec section 6.2).
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actor_device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('actor_node_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->text('reason')->nullable();
            $table->string('source_context');
            $table->json('signature_metadata_json')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
