<?php

namespace App\Models;

use Database\Factories\EquipmentCheckoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentCheckout extends Model
{
    /** @use HasFactory<EquipmentCheckoutFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'equipment_item_id',
        'event_id',
        'staff_id',
        'shift_id',
        'checked_out_at',
        'checked_out_by_user_id',
        'returned_at',
        'returned_by_user_id',
        'return_condition',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_out_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by_user_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    /**
     * The equipment this event still has in somebody's hands on behalf of one
     * department, whether or not it is coming back.
     *
     * Department scope is the item's own department or no department at all,
     * because an unscoped item is repair-tooling inventory (EQUIP-006) and the
     * desk holding it is still the desk answerable for it. Staff scope is left
     * to the caller: the presence command asks about one person, the Logistics
     * Desk index asks about a department at once, and the difference between
     * them should not be a second copy of this rule.
     *
     * @param  Builder<EquipmentCheckout>  $query
     */
    public function scopeOutstandingForDepartment(Builder $query, Event $event, Department $department): void
    {
        $query
            ->where('event_id', $event->id)
            ->whereNull('returned_at')
            ->whereHas('equipmentItem', fn (Builder $item) => $item->where(function (Builder $scope) use ($department): void {
                $scope->whereNull('department_id')->orWhere('department_id', $department->id);
            }));
    }

    /**
     * Whether this outstanding checkout is one of the ones that keeps its holder
     * on site (SLB-018).
     *
     * Not every unreturned checkout does. SLB-018 blocks an off-site mark while
     * somebody holds checked-out equipment "unless the equipment is returned or
     * marked Missing/Damaged", so an item God Mode has written off is still owed
     * to the department and is no longer a reason to keep a person standing at
     * the desk. `DepartmentPresenceService` refuses on this answer and the
     * Logistics Desk read states it, so both ask the checkout itself.
     *
     * Requires `equipmentItem` to be loaded.
     */
    public function blocksOffSite(): bool
    {
        return $this->returned_at === null
            && $this->equipmentItem?->status === EquipmentItem::STATUS_CHECKED_OUT;
    }
}
