<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a login code be issued with no workstation named (M18.58; AUTH-031;
 * technical spec 13.2, Untargeted codes; data/API 12.4).
 *
 * `shared_workstation_id` was created NOT NULL in M3.8, so a code could only
 * ever be scoped to a workstation chosen at generation — and the person
 * generating one on their phone has no way to know which workstation they will
 * be standing at. The column becomes nullable, and redemption stamps it with
 * the workstation the code was actually used at, so a spent code names where it
 * was spent whichever way it was issued.
 *
 * The `['shared_workstation_id', 'event_id']` index is left alone: a null in it
 * constrains nothing, and redemption fills the column so the index carries every
 * spent code either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_workstation_login_codes', function (Blueprint $table): void {
            $table->uuid('shared_workstation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shared_workstation_login_codes', function (Blueprint $table): void {
            $table->uuid('shared_workstation_id')->nullable(false)->change();
        });
    }
};
