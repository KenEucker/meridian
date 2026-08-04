<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization interest submissions from the public marketing surface (M18.23;
 * PUBLIC-002, PUBLIC-003, PUBLIC-004).
 *
 * An inquiry is the only thing an organization interest submission creates.
 * PUBLIC-003 is explicit that it creates no organization, no user, no staff
 * record, and no operational data, and this table is the shape of that rule:
 * it holds four fields somebody typed and a review state, and it carries no
 * foreign key into the operational model except the God Mode user who reviewed
 * it. Nothing joins to it, so nothing can grow out of it by accident.
 *
 * Organization creation stays a God Mode action (PUBLIC-004), which is why
 * there is no `organization_id` here even after an inquiry has been acted on.
 * An organization created from a conversation that started here is created the
 * ordinary way; linking the two rows would invite the console to grow a
 * create-from-inquiry path that PUBLIC-004 does not want.
 *
 * No IP address is stored. Rate limiting bounds submission (PUBLIC-005) and
 * does it in the cache against a hashed client key, so the deterrent does not
 * require keeping a log of who visited a marketing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_inquiries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The four fields PUBLIC-002 names, and no others. The form is
            // fixed rather than configurable: it exists to start a
            // conversation, not to collect an application.
            $table->string('organization_name');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->text('description');

            // Review state (PUBLIC-004). `new` until somebody in God Mode has
            // read it, `reviewed` once they have and the conversation is live,
            // `closed` when there is nothing further to do. A closed inquiry is
            // kept rather than deleted: it is the record that somebody asked.
            $table->string('status', 32)->default('new');
            $table->text('review_notes')->nullable();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
            $table->index('contact_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_inquiries');
    }
};
