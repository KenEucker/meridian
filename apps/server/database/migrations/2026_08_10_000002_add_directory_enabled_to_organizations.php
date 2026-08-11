<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the organization has the Directory at all (M18.72; DIR-004,
 * DIR-005; technical spec 21E.9).
 *
 * One boolean on `organizations`, defaulting to enabled, because DIR-004 says
 * an organization that has never opened the configuration surface has a
 * Directory. It is availability, not visibility: it decides whether the
 * surface exists for the organization, and who appears within one is the
 * M18.71 visibility rule and nothing else.
 *
 * This column is the Directory's whole schema footprint. The chart owns no
 * table of its own — departments, teams, memberships, leadership, and status
 * all belong to the domains that already hold them (technical spec 21E.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('directory_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('directory_enabled');
        });
    }
};
