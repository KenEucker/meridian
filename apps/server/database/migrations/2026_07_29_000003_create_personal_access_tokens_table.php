<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum bearer tokens issued to Meridian client applications (AUTH-018,
 * AUTH-024; technical spec 11.4; data/API specification 12.5).
 *
 * Meridian ships its own copy of Sanctum's table rather than publishing the
 * package migration because `users.id` is a UUID, so the polymorphic owner
 * columns need `uuidMorphs` rather than the package's integer `morphs`.
 *
 * The `device_id` binding and `revoked_at` column that data/API 12.5 also
 * lists arrive with device-bound tokens and God Mode revocation (M16.2). Only
 * the columns Sanctum itself reads are created here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');

            // Only the SHA-256 hash of a token is stored, so a database read
            // cannot recover a usable credential (AUTH-025).
            $table->string('token', 64)->unique();

            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
