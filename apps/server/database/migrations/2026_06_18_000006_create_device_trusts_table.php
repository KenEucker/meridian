<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_trusts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $table->string('trusted_node_fingerprint');
            $table->timestamp('first_trusted_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->timestamp('revoked_at')->nullable()->index();

            $table->unique(['user_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_trusts');
    }
};
