<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device binding and revocation for issued bearer tokens (AUTH-021, AUTH-022,
 * AUTH-023; technical spec 11.4; data/API specification 12.5).
 *
 * `device_id` is `NOT NULL` because data/API 12.5 states the invariant without
 * an exception: "a token is always bound to a `devices` record". A nullable
 * column would make an unbound token representable, and an unbound token is
 * exactly what device revocation cannot reach.
 *
 * The table is recreated rather than altered for two reasons. SQLite cannot add
 * a `NOT NULL` column to an existing table at all, so an alter would leave the
 * development and test databases without the constraint the production database
 * carries. And a token issued before this migration has no device to be bound
 * to and cannot acquire one retroactively, so it must not survive — the clients
 * holding one sign in again, which is the same cost as a node restart with a
 * shortened lifetime. Dropping the table is therefore the honest form of the
 * change rather than a shortcut around it.
 *
 * `revoked_at` records a revocation without deleting the row, so an operator can
 * still account for a token that was revoked, and so audit entries referencing
 * it by identifier keep resolving. Revocation is evaluated at request time; see
 * {@see \App\Services\Auth\ApiTokenAuthentication}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
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
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
