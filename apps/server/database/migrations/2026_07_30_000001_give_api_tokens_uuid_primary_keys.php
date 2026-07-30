<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API tokens are keyed by UUID like every other Meridian entity (AUTH-025;
 * data/API specification 12.5, 13.1).
 *
 * The table arrived from Sanctum with an auto-incrementing key, which is the one
 * integer identifier in the schema. `audit_events.entity_id` is a `uuid` column
 * — every entity in Meridian is identified by one — so auditing a token by its
 * identifier wrote an integer into a UUID column. PostgreSQL refuses that
 * outright, which meant token issuance failed on the database Meridian actually
 * deploys on: a client signing in got a 500 and no token. SQLite accepts it, so
 * the suite never saw it, and the failure only appeared the first time a client
 * signed in against a real node.
 *
 * Auditing issuance, expiry, and revocation is required (AUTH-025), so the token
 * has to carry an identifier the audit trail can hold. Making the token conform
 * is the smaller change than making the audit log accept two kinds of identifier
 * — and the audit entries that already reference tokens keep resolving, because
 * on PostgreSQL there were never any to write.
 *
 * Recreated rather than altered, as in the device-binding migration before it: a
 * primary key cannot be retyped in place on SQLite, and a token issued under an
 * integer key cannot acquire a UUID retroactively. The clients holding one sign
 * in again, which is the same cost as a node restart with a shortened lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');

            // Restricted rather than cascading: a device with tokens is history
            // an operator revokes, not history that disappears when the device
            // row does.
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();

            $table->text('name');

            // Only the SHA-256 hash of a token is stored, so a database read
            // cannot recover a usable credential (AUTH-025).
            $table->string('token', 64)->unique();

            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
