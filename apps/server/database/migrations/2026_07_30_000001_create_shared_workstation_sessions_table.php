<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The session a shared-workstation login code establishes (AUTH-030;
     * technical spec 13.3; data/API 12.3).
     *
     * A separate table from `personal_access_tokens` because these are separate
     * credentials with separate rules. AUTH-030 says a code entry issues no
     * personal device token, and the two lifetimes are independent (AUTH-024): a
     * bearer token lasts as long as the node configures, while this expires five
     * minutes after the last thing the person did. Storing a workstation session
     * as a token would have made the distinction a column rather than a type,
     * and something would eventually have treated one as the other.
     */
    public function up(): void
    {
        Schema::create('shared_workstation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shared_workstation_id')->constrained('shared_workstations')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();

            // The event the session is scoped to, taken from the code rather than
            // the request. No foreign key, matching
            // `shared_workstation_login_codes.event_id` — the shared-workstation
            // context columns predate the `events` migration and the formal
            // constraint stays deferred with them.
            $table->uuid('event_id')->index();

            $table->foreignUuid('login_code_id')
                ->constrained('shared_workstation_login_codes')
                ->restrictOnDelete();

            // Only a keyed hash, for the same reason the login code itself is
            // only stored hashed: the raw value is a credential, and a database
            // or backup that leaks should not hand out live sessions.
            $table->string('session_key_hash')->unique();

            $table->timestamp('started_at');

            // The inactivity clock. Every authenticated request slides it, and
            // the session is over five minutes after the last slide whether or
            // not anything has yet noticed (technical spec 13.3).
            $table->timestamp('last_activity_at')->index();

            $table->timestamp('ended_at')->nullable()->index();

            // Why it ended: signed out, timed out, or superseded by a newer
            // session at the same workstation.
            $table->string('ended_reason')->nullable();

            $table->timestamps();

            // The lookup the guard makes on every request: the open sessions at
            // one workstation.
            $table->index(['shared_workstation_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_workstation_sessions');
    }
};
