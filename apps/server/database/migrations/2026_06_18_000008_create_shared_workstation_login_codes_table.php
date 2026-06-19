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
        Schema::create('shared_workstation_login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id')->index();
            $table->foreignUuid('shared_workstation_id')->constrained('shared_workstations')->restrictOnDelete();
            $table->string('code_hash')->index();
            $table->timestamp('expires_at')->index();
            $table->foreignUuid('generated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'event_id']);
            $table->index(['shared_workstation_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_workstation_login_codes');
    }
};
