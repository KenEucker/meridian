<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time pairing tokens created by a central node so an on-site or
     * standalone node can pair with it (technical spec 7.3, 7.4).
     *
     * Only the token hash is stored; the plaintext token is shown once at
     * issue time. Alpha 1 does not require quick expiry, so `expires_at` is
     * nullable and unset by default.
     */
    public function up(): void
    {
        Schema::create('node_pairing_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('issued_by_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignUuid('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->foreignUuid('paired_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_pairing_tokens');
    }
};
