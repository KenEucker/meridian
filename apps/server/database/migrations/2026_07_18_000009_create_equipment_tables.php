<?php

use App\Models\EquipmentItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Equipment tracking is a visible/manual MVP workflow (SLB-011, SLB-012;
     * EQUIP-001 through EQUIP-005; data/API section 10.13). Full custody
     * chains and department-to-department allotments are intentionally omitted.
     */
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('name');
            $table->string('asset_tag')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('status')->default(EquipmentItem::STATUS_AVAILABLE)->index();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable()->index();

            $table->index(['organization_id', 'archived_at']);
            $table->index(['event_id', 'department_id']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('equipment_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_item_id')->constrained('equipment_items')->restrictOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->timestamp('checked_out_at')->index();
            $table->foreignUuid('checked_out_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at')->nullable()->index();
            $table->foreignUuid('returned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('return_condition')->nullable();
            $table->timestamps();

            $table->index(['equipment_item_id', 'returned_at']);
            $table->index(['event_id', 'staff_id']);
            $table->index(['shift_id', 'returned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment_checkouts');
        Schema::dropIfExists('equipment_items');
    }
};
