<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff profile change requests (M18.20A; VOL-017 through VOL-026; data/API
 * 10.4).
 *
 * One table for both request kinds — a handle change beyond the self-service
 * allowance and a profile picture submission — because they share their whole
 * lifecycle: pending, then approved, rejected, or withdrawn, with one
 * outstanding request per kind per staff member and the same reviewers.
 *
 * The pending picture columns hold the submitted image while it awaits review.
 * The staff record's own picture columns are never touched by a submission
 * (VOL-021): approval moves the image across, rejection and withdrawal discard
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profile_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('status', 32);
            $table->string('previous_handle')->nullable();
            $table->string('requested_handle')->nullable();
            $table->string('pending_picture_path', 2048)->nullable();
            $table->string('pending_picture_mime_type', 128)->nullable();
            $table->unsignedBigInteger('pending_picture_size_bytes')->nullable();
            $table->unsignedInteger('pending_picture_width')->nullable();
            $table->unsignedInteger('pending_picture_height')->nullable();
            // Whether the change applied without review under the VOL-017
            // allowance. The allowance itself is counted from applied rows, so
            // there is deliberately no counter column anywhere (VOL-018).
            $table->boolean('self_service')->default(false);
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            // The reviewer's list and the one-outstanding-per-kind check both
            // ask this shape. Uniqueness of the pending row is enforced by the
            // domain service inside its transaction, because a partial unique
            // index is not portable to the sqlite the test suite runs on.
            $table->index(['staff_id', 'kind', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profile_change_requests');
    }
};
