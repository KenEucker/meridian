<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization audit volume configuration (data/API 10.1, 14.1).
 *
 * Two separate controls, because they answer two different questions and an
 * organization will usually want different answers to them:
 *
 *  - **How much is written.** `audit_verbosity` picks one of five levels, and
 *    `audit_action_overrides` carries the per-action exceptions to that level
 *    for an operator who wants one specific thing recorded or not. Neither can
 *    switch off an entry that requirements 2.4 or data/API section 8 require —
 *    `AuditActionCatalog::REQUIRED` is the floor, and it is enforced in the
 *    write path rather than in the surface that sets these.
 *  - **How much is kept.** `audit_max_rows`, `audit_max_bytes`, and
 *    `audit_retention_days` bound the stored history. Reaching a bound does not
 *    delete anything on its own: `AuditArchivalService` exports the oldest rows
 *    to a signed archive first and records the archival as its own audit entry,
 *    so the history leaves the table without leaving the record.
 *
 * Every column is nullable and every null reads as the documented default, the
 * way the M18.14 configuration columns do. Storing a default would make an
 * organization that never chose look identical to one that chose it, and a
 * later change of default would silently not reach the first of them. Null on
 * the three limits means unlimited, which is what every organization has today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // One of the five `AuditVerbosity` levels. Null reads as `standard`.
            $table->string('audit_verbosity', 32)->nullable();

            /*
             * Per-action exceptions, as `{action: bool}`. The advanced control
             * behind the five-level choice, for the operator who wants one
             * action recorded that their level omits, or one omitted that it
             * includes. An override naming a required action is ignored by the
             * write path rather than rejected here, because the catalog is the
             * thing that knows which those are.
             */
            $table->json('audit_action_overrides')->nullable();

            // Null on each of these means no bound.
            $table->unsignedBigInteger('audit_max_rows')->nullable();
            $table->unsignedBigInteger('audit_max_bytes')->nullable();
            $table->unsignedInteger('audit_retention_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'audit_verbosity',
                'audit_action_overrides',
                'audit_max_rows',
                'audit_max_bytes',
                'audit_retention_days',
            ]);
        });
    }
};
