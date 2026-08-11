<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One user's decision about whether one page appears in their menus (M18.69).
 *
 * A separate table from `hidden_page_preferences` rather than a second column
 * on it, because the two record different decisions about different sets of
 * pages. That one says a page is put away — gone from the menus and off the
 * home directory. This one says a page stays on Home and comes out of the
 * menus, which is the narrower thing somebody wants when their menu has grown
 * past what they use hourly. A single row carrying both would have to describe
 * a page that is hidden and also out of the menu, which is a state with one
 * meaning and two ways to write it.
 *
 * Keyed on the user for the same reason as its sibling: this is a property of
 * the login, and a person speaking for two staff profiles is one person reading
 * one menu.
 *
 * `hidden` is a column rather than the row's presence being the answer. Nothing
 * in the catalog starts out of the menus today, so presence alone would in fact
 * carry it — but a page given a menu-absent default later would need a data
 * migration to say "this reader wants it anyway", and a stored decision is what
 * keeps somebody's answer stable when a default moves underneath them.
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
        Schema::create('menu_page_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            /*
             * The page key from `MenuPageCatalog`, stored as text rather than
             * as a foreign key: the catalog is code, the set changes with
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
        Schema::dropIfExists('menu_page_preferences');
    }
};
