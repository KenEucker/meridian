<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider login handoffs between a client application and the system browser
 * (AUTH-020; technical spec 11.4; data/API specification 5.4).
 *
 * A client cannot follow a Google or Discord redirect itself — the provider
 * exchange is not reimplemented in the client — so it hands the sign-in to the
 * system browser and waits. This row is what the two halves of that flow share:
 * the client starts a handoff, the browser completes it at the provider callback,
 * and the client exchanges the result for a bearer token.
 *
 * Both credentials on the row are stored as keyed hashes rather than as values.
 * `state_hash` proves a provider callback belongs to a handoff this node started,
 * and `exchange_code_hash` is the one-time credential the browser carries back to
 * the client. Neither is recoverable from the database, for the same reason a raw
 * token is not (AUTH-025).
 *
 * `code_challenge` is the client's PKCE challenge (RFC 7636), held so redemption
 * can require the verifier only the client that started the handoff holds. The
 * return leg travels through a custom scheme on mobile and desktop, where another
 * application can register the same scheme, so possession of the exchange code
 * alone must not be enough to obtain a token.
 *
 * The row is kept after it is spent. A handoff records that a provider sign-in
 * happened, which client target it returned to, and whether it succeeded, and
 * that is operator-visible history rather than scratch state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_auth_handoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->index();

            // Which registered return target the browser is sent back to. The
            // client names the target and the node resolves the URI from its own
            // configuration, so a request cannot nominate a return address of
            // its own choosing.
            $table->string('client_target', 32);
            $table->string('redirect_uri');

            $table->string('state_hash', 64)->unique();
            $table->string('code_challenge', 128);
            $table->string('exchange_code_hash', 64)->nullable()->unique();

            // Null until the provider callback resolves an identity, and null
            // forever on a handoff that failed. Restricted rather than
            // cascading: a user row is not deleted in Meridian, and a handoff
            // that outlived its user is a repair problem rather than something
            // to silently drop.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('status', 32)->index();
            $table->string('failure_reason', 64)->nullable();

            $table->timestamp('expires_at')->index();
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_auth_handoffs');
    }
};
