<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the conflict queue hold a refused device write (MOD-017; technical spec
 * 15A.8; data/API 7.5, 7.6, 14.2; M19.17).
 *
 * Every conflict until now was a node-to-node disagreement: two installs holding
 * two versions of one entity, with a stored, signed {@see \App\Models\NodeOperation}
 * carrying the version that lost. `operation_id` was NOT NULL because that shape
 * always has one.
 *
 * MOD-017 adds a conflict of a different shape. A write queued on somebody's
 * device against a module the organization has since switched off is refused
 * when it lands, and the requirement says it is "recorded as a sync conflict for
 * God Mode resolution, rather than silently dropped or silently applied". There
 * is no node operation behind it and there must not be one: a device write is not
 * a node-to-node operation, and minting a signed operation to hold it would put a
 * sync event that never happened into an append-only log and into the outbox to
 * peers. So the column becomes nullable, and a conflict with no operation is
 * exactly what it says — a refusal that came from a device rather than from a
 * node.
 *
 * `origin_operation_uuid` is the device-generated key that write was queued under
 * (technical spec 11A.5; data/API 5.3, 5.6). It is what the operation UUID is for
 * a node operation — the identity a repeated delivery resolves to — so it is
 * unique: a device that sends its queued write again after a lost reply meets the
 * conflict it already has rather than filling the queue with copies of one
 * refusal. Nullable, because a node-to-node conflict names its operation by
 * `operation_id` and has no device key at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table): void {
            $table->uuid('operation_id')->nullable()->change();
            $table->uuid('origin_operation_uuid')->nullable()->after('operation_id');
            $table->unique('origin_operation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('sync_conflicts', function (Blueprint $table): void {
            $table->dropUnique(['origin_operation_uuid']);
            $table->dropColumn('origin_operation_uuid');
            $table->uuid('operation_id')->nullable(false)->change();
        });
    }
};
