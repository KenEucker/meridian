<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sign-in request a locked trusted workstation opens and presents as a
 * scannable code (M18.59; AUTH-032 through AUTH-037; technical spec 13.4;
 * data/API 12.4A), and the `short_code` a person types when the camera cannot
 * scan (data/API 12.3).
 *
 * The request id is public — it travels in the QR — and grants nothing by
 * itself. What later collects a session key is the pickup secret, held only by
 * the workstation that opened the request and stored here only as a keyed
 * hash. That split is the whole reason the unauthenticated open route is safe:
 * everything a bystander can photograph is the public half.
 *
 * `shared_workstation_sessions` gains the other half of the same change: a
 * session established by a collected request has no login code behind it, so
 * `login_code_id` becomes nullable and `sign_in_request_id` records the
 * provenance instead. Exactly one of the two is set either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_workstations', function (Blueprint $table): void {
            // The typed fallback for a dead camera: a short human-typable
            // identifier, unique per event, displayed on the locked Kiosk. It
            // identifies the workstation and is not a credential.
            $table->string('short_code')->nullable();

            $table->unique(['event_id', 'short_code']);
        });

        Schema::create('shared_workstation_sign_in_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shared_workstation_id')->constrained('shared_workstations')->restrictOnDelete();

            // The workstation's pinned event at opening. No foreign key,
            // matching the other shared-workstation context columns.
            $table->uuid('event_id')->index();

            // `sign_in` or `reauthentication`. A request opened for one purpose
            // cannot be collected as the other (AUTH-036).
            $table->string('purpose');

            // The live session a re-authentication request is bound to; null
            // for a sign-in request.
            $table->foreignUuid('shared_workstation_session_id')
                ->nullable()
                ->constrained('shared_workstation_sessions')
                ->restrictOnDelete();

            // Only a keyed hash of the pickup secret is stored (AUTH-037).
            $table->string('pickup_secret_hash');

            $table->foreignUuid('granted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('expires_at')->index();

            $table->timestamps();

            // The poll a locked Kiosk makes: this workstation's open requests.
            $table->index(['shared_workstation_id', 'expires_at']);
        });

        Schema::table('shared_workstation_sessions', function (Blueprint $table): void {
            $table->uuid('login_code_id')->nullable()->change();

            $table->foreignUuid('sign_in_request_id')
                ->nullable()
                ->constrained('shared_workstation_sign_in_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shared_workstation_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sign_in_request_id');
            $table->uuid('login_code_id')->nullable(false)->change();
        });

        Schema::dropIfExists('shared_workstation_sign_in_requests');

        Schema::table('shared_workstations', function (Blueprint $table): void {
            $table->dropUnique(['event_id', 'short_code']);
            $table->dropColumn('short_code');
        });
    }
};
