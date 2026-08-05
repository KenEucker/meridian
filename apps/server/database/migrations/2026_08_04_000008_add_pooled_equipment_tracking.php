<?php

use App\Models\EquipmentItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pooled and individually tracked equipment (M18.24B; EQUIP-010, EQUIP-011,
 * EQUIP-016, EQUIP-017; data/API 10.13).
 *
 * Every equipment record Meridian holds today is one physical unit with an asset
 * tag on it, and every checkout hands over exactly that unit. That is the
 * `individual` kind, so the backfill is not a conversion: it writes down what
 * the rows already are. `tracking` defaults to `individual` and both quantity
 * columns default to 1, which means a row written by code that predates this
 * migration still lands correct.
 *
 * A pool's availability is deliberately not a column. EQUIP-016 derives it from
 * `quantity_total` less the open checkout quantity, because a stored count is a
 * second answer that drifts from the checkouts the moment one of them is written
 * outside the service that maintains it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('tracking')
                ->default(EquipmentItem::TRACKING_INDIVIDUAL)
                ->after('name');
            // The pool size for a pooled record, 1 for a tracked unit. Missing
            // and damaged pooled returns reduce it through an audited
            // adjustment (EQUIP-017), so it is the serviceable total rather
            // than the number ever bought.
            $table->unsignedInteger('quantity_total')->default(1)->after('serial_number');

            $table->index(['department_id', 'tracking']);
        });

        Schema::table('equipment_checkouts', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('shift_id');
            // Accumulates across partial returns; the checkout closes when it
            // reaches `quantity` (data/API 10.13).
            $table->unsignedInteger('quantity_returned')->nullable()->after('quantity');
        });

        // Existing rows already carry the defaults through the column
        // definition; this states it for a database that adds the column
        // without applying the default to the rows already there.
        DB::table('equipment_items')->update([
            'tracking' => EquipmentItem::TRACKING_INDIVIDUAL,
            'quantity_total' => 1,
        ]);

        DB::table('equipment_checkouts')->update(['quantity' => 1]);

        DB::table('equipment_checkouts')
            ->whereNotNull('returned_at')
            ->update(['quantity_returned' => 1]);
    }

    public function down(): void
    {
        Schema::table('equipment_checkouts', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'quantity_returned']);
        });

        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'tracking']);
            $table->dropColumn(['tracking', 'quantity_total']);
        });
    }
};
