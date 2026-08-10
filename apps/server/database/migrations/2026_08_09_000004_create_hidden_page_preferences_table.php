<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One user's decision about whether one page appears in their navigation
 * (M18.69).
 *
 * Keyed on the user rather than on a staff record, because this is a property
 * of the login: a user speaking for two staff profiles is one person reading
 * one menu, and a preference stored per profile would give them two answers to
 * a question that has one.
 *
 * `hidden` is a column rather than the row's presence being the whole answer —
 * which is where this differs from `event_horizon_dismissals`. A page may be
 * hidden by default, so "shown" is a decision somebody can make and has to be
 * storable; presence alone could only ever record half the states. A user who
 * has decided nothing has no row and gets the catalog's default.
 *
 * Not audited, and invisible to everyone but its own user. Nobody can write
 * anybody else's row: the command takes no subject, so `user_id` is always the
 * caller (technical spec 21D.10 — personal view state is not a record of
 * anything operational).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hidden_page_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            /*
             * The page key from `HideablePageCatalog`, stored as text rather
             * than as a foreign key: the catalog is code, the set changes with
             * releases rather than with data, and a key it no longer knows is
             * simply never resolved.
             */
            $table->string('page_key');
            $table->boolean('hidden');
            $table->timestamps();

            $table->unique(['user_id', 'page_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_page_preferences');
    }
};
