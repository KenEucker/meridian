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
    /**
     * Issued for one shift, and owed back when that shift ends (EQUIP-009).
     */
    public const SCOPE_SHIFT = 'shift';

    /**
     * Issued for the event, and owed back when the holder leaves site
     * (EQUIP-009).
     */
    public const SCOPE_EVENT = 'event';

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
        'quantity',
        'quantity_returned',
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
            'quantity' => 'integer',
            'quantity_returned' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function assignmentScopeLabels(): array
    {
        return [
            self::SCOPE_SHIFT => 'Shift',
            self::SCOPE_EVENT => 'Event',
        ];
    }

    /**
     * Whether this checkout was issued for a shift or for the event (EQUIP-009).
     *
     * Derived from `shift_id` rather than stored beside it. Two columns saying
     * the same thing is one column too many, and the one that can disagree is
     * always the redundant one — a checkout with a shift *is* shift-assigned,
     * and there is no third answer to record.
     */
    public function assignmentScope(): string
    {
        return $this->shift_id === null ? self::SCOPE_EVENT : self::SCOPE_SHIFT;
    }

    /** Units still owed on this checkout, after any partial return. */
    public function quantityOutstanding(): int
    {
        return max(0, (int) ($this->quantity ?? 1) - (int) ($this->quantity_returned ?? 0));
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
     * A pooled checkout blocks whenever it is open. There is no written-off
     * pool to make the exception from: EQUIP-016 keeps a pool out of
     * `checked_out`, and EQUIP-017 writes a loss off against the pool's
     * serviceable total at return rather than by moving the record into a
     * state. So the only pooled units that stop blocking are the ones that have
     * come back, which is the rule stated the other way round.
     *
     * Requires `equipmentItem` to be loaded.
     */
    public function blocksOffSite(): bool
    {
        if ($this->returned_at !== null) {
            return false;
        }

        if ($this->equipmentItem?->isPooled() === true) {
            return $this->quantityOutstanding() > 0;
        }

        return $this->equipmentItem?->status === EquipmentItem::STATUS_CHECKED_OUT;
    }
}
