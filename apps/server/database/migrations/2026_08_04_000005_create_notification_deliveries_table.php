<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional notification delivery records and the per-organization send
 * suppression switch (M18.21; NOTIFY-005 through NOTIFY-009).
 *
 * One row per person told, or per person deliberately not told. NOTIFY-007
 * asks that an operator be able to answer whether a person was told, which is
 * a question a mail transport log cannot answer for a notification that was
 * never handed to a transport at all: an unverified address and a suppressed
 * organization both end in nothing arriving, and only a record written before
 * the decision distinguishes them from a send that failed. So the row is
 * written first and its outcome is stamped on it afterwards.
 *
 * `context_json` holds identifiers and scope, never a rendered subject or
 * body. NOTIFY-007 keeps message bodies out of the retained trail, and the
 * message is rebuilt from the subject record at send time, so there is nothing
 * a body would add that the subject record does not already say.
 *
 * `organizations.notifications_suppressed_at` is the NOTIFY-009 switch. A
 * timestamp rather than a boolean because "suppressed since" is the fact an
 * operator wants when a restored backup stops mailing: a boolean answers
 * whether, and nothing about when it was decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('notification_type', 64);

            // Scope, for NOTIFY-004's requirement that a notification names the
            // organization and department it concerns, and for reading the
            // trail back per organization.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // The subject record: what happened, to which row.
            $table->string('subject_entity_type', 191);
            $table->string('subject_entity_id', 64);

            // The recipient. `recipient_user_id` is null for an applicant with
            // no user account, whom NOTIFY-005 addresses at the application
            // email address instead.
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('recipient_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('recipient_email');

            $table->string('status', 32);
            $table->string('outcome_reason', 191)->nullable();
            $table->json('context_json')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Which node decided this, so a delivery held for central on-site
            // and the one central actually sent are distinguishable
            // (NOTIFY-008).
            $table->uuid('origin_node_id')->nullable()->index();
            // Not unique: one node operation can be the origin of several
            // deliveries, because a cancelled shift is one operation and one
            // notification per person who was signed up for it.
            $table->uuid('origin_operation_uuid')->nullable()->index();

            $table->timestamps();

            $table->index(['notification_type', 'subject_entity_type', 'subject_entity_id'], 'notification_deliveries_subject_index');
            $table->index(['recipient_email', 'notification_type']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('notifications_suppressed_at')->nullable()->after('handle_self_service_change_limit');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('notifications_suppressed_at');
        });

        Schema::dropIfExists('notification_deliveries');
    }
};
