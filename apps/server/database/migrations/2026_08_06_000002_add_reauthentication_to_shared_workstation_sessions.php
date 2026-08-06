<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a shared-workstation session was last re-authenticated (M18.32; UI-017;
 * UI contract 12.8 `kiosk.reauth`, 18.2).
 *
 * "Privileged actions may require re-authentication" is a rule about a machine
 * strangers stand in front of, and a rule of that kind has to leave a record on
 * the node. A confirmation the renderer alone remembers is a confirmation
 * anybody who reloads the page can grant themselves.
 *
 * The column is nullable and stays null for the ordinary session. It carries no
 * default and no expiry of its own: how recent a confirmation must be is the
 * question of whichever action asks for one, and that action reads this against
 * its own window rather than against a duration frozen into the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_workstation_sessions', function (Blueprint $table): void {
            $table->timestamp('reauthenticated_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('shared_workstation_sessions', function (Blueprint $table): void {
            $table->dropColumn('reauthenticated_at');
        });
    }
};
