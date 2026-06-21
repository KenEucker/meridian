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
        Schema::create('waivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('scope_type');
            $table->uuid('scope_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('expires_after_days')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['organization_id', 'scope_type', 'scope_id']);
            $table->index(['organization_id', 'archived_at']);
        });

        Schema::create('waiver_completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('waiver_id')->constrained('waivers')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['waiver_id', 'staff_id']);
            $table->index(['staff_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waiver_completions');
        Schema::dropIfExists('waivers');
    }
};
