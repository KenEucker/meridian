<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device-generated key an unscheduled addition carries (M18.54; SLB-008;
 * CLIENT-015, CLIENT-016; data/API 5.3, 5.6, 7.2).
 *
 * The Logistics addition becomes an Alpha 1 offline write, which means it is
 * sent by a queue rather than by a person: the same command may reach the node
 * twice when a reply is lost on a field network. Every other offline write
 * answers that with the operation UUID the device minted before it had a node
 * to ask — attendance stores it on `attendance_operations` — and this is the
 * addition's copy of that property.
 *
 * Nullable, because an addition made at a connected desk before this milestone
 * has no such key and one invented for it afterwards would claim a provenance
 * it never had. Unique where present, which is what makes a replay the same
 * command rather than a second assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table): void {
            $table->uuid('unscheduled_operation_uuid')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table): void {
            $table->dropUnique(['unscheduled_operation_uuid']);
            $table->dropColumn('unscheduled_operation_uuid');
        });
    }
};
