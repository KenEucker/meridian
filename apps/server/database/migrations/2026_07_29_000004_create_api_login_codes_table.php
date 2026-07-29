<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use codes a client application exchanges for a bearer token
 * (AUTH-019; technical spec 11.4; data/API specification 5.4).
 *
 * The web magic link is a signed URL and needs no state, but an API client
 * completes verification "without leaving the application", so what the node
 * issues has to be something a person can carry from their email back into the
 * app they started in. That is a code, and a code has to be stored to be
 * checked once and then retired.
 *
 * The shape follows `shared_workstation_login_codes` (data/API 12.4): only a
 * keyed hash of the code is persisted, and `used_at` plus `attempts` retire a
 * code after one success or a handful of failures. The row is keyed by email
 * rather than by user because, exactly as with the web magic link, the email
 * may not resolve to a user account until verification succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->index();
            $table->string('code_hash', 64)->index();
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamps();

            $table->index(['email', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_login_codes');
    }
};
