<?php

namespace App\Services\Equipment;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Permissions\DepartmentOperationalAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manual MVP equipment checkout/check-in workflow (SLB-011, SLB-012;
 * EQUIP-001 through EQUIP-005, EQUIP-009 through EQUIP-011, EQUIP-016,
 * EQUIP-017). Equipment is checked out to individual staff members; full
 * inventory custody chains and allotments stay future scope.
 *
 * Two kinds of record pass through here and they behave differently on purpose
 * (EQUIP-010). An individually tracked item is one physical thing: a checkout
 * names it, its stored state becomes `checked_out`, and a second open checkout
 * against it is a contradiction the service refuses. A pool is a quantity: a
 * checkout takes some of it, several may be open at once, the record is never
 * stored `checked_out` because nobody holds the pool (EQUIP-016), and what is
 * available is derived from the total less what is out rather than read from a
 * column that would have to be kept in step.
 *
 * A pooled return may come back in parts, and units that come back missing or
 * damaged reduce the pool's serviceable total through an audited adjustment
 * carrying a reason rather than moving the pool into a state (EQUIP-017).
 */
class EquipmentCheckoutService
{
    /**
     * @var list<string>
     */
    private const RETURN_CONDITIONS = [
        EquipmentItem::STATUS_RETURNED,
        EquipmentItem::STATUS_MISSING,
        EquipmentItem::STATUS_DAMAGED,
    ];

    public function __construct(
        private readonly DepartmentOperationalAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws EquipmentCheckoutException
     */
    public function checkoutEquipment(
        EquipmentItem $equipmentItem,
        Staff $staff,
        User $actor,
        ?Shift $shift = null,
        ?Carbon $checkedOutAt = null,
        int $quantity = 1,
        ?Event $event = null,
    ): EquipmentCheckoutResult {
        $checkedOutAt ??= Carbon::now();

        if ($quantity < 1) {
            throw EquipmentCheckoutException::invalidQuantity();
        }

        return DB::transaction(function () use ($equipmentItem, $staff, $actor, $shift, $checkedOutAt, $quantity, $event): EquipmentCheckoutResult {
            $equipmentItem = EquipmentItem::query()
                ->with(['event', 'department', 'organization'])
                ->whereKey($equipmentItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $shift = $shift === null
                ? null
                : Shift::query()
                    ->with(['event', 'department'])
                    ->whereKey($shift->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

            if ($equipmentItem->isArchived()) {
                throw EquipmentCheckoutException::archivedEquipment();
            }

            if ($shift !== null) {
                $this->authorizeShiftCheckout($actor, $shift);
                $this->validateEquipmentShiftScope($equipmentItem, $shift);
                $this->ensureRosteredStaff($shift, $staff);

                $eventId = (string) $shift->event_id;
                $departmentId = (string) $shift->department_id;
            } else {
                /*
                 * An event-assigned checkout (EQUIP-009). The event comes from
                 * the item when the item names one, and from the desk making
                 * the handoff when it does not — most department stock is
                 * scoped to the department rather than to an event, and before
                 * M18.24 that stock could not be signed out for the event at
                 * all: the desk offered it and the command refused it for
                 * having no event context. A pool is almost always department
                 * stock, so the gap would have swallowed the common case.
                 */
                $checkoutEvent = $equipmentItem->event ?? $event;

                $this->authorizeDepartmentCheckout($actor, $equipmentItem, $checkoutEvent);
                $this->ensureStaffInEquipmentDepartment($equipmentItem, $staff);

                $eventId = $checkoutEvent === null ? '' : (string) $checkoutEvent->id;
                $departmentId = $equipmentItem->department_id === null ? null : (string) $equipmentItem->department_id;
            }

            if ($eventId === '') {
                throw EquipmentCheckoutException::noEventContext();
            }

            $pooled = $equipmentItem->isPooled();

            if (! $pooled) {
                if ($quantity !== 1) {
                    throw EquipmentCheckoutException::trackedQuantityMustBeOne();
                }

                $openCheckout = EquipmentCheckout::query()
                    ->where('equipment_item_id', $equipmentItem->id)
                    ->whereNull('returned_at')
                    ->lockForUpdate()
                    ->first();

                if ($openCheckout !== null) {
                    return $this->existingOpenCheckout($openCheckout, $equipmentItem, $staff, $shift);
                }

                if (! $equipmentItem->canBeCheckedOut()) {
                    throw EquipmentCheckoutException::equipmentUnavailable($equipmentItem->status);
                }
            } else {
                // The row is already locked, so the availability derived here
                // is the availability this checkout is being weighed against —
                // two operators handing out the last two radios at once
                // serialize rather than both succeeding (EQUIP-016).
                $available = $equipmentItem->availableQuantity();

                if ($quantity > $available) {
                    throw EquipmentCheckoutException::insufficientPoolQuantity(
                        $equipmentItem->name,
                        $available,
                        $quantity,
                    );
                }
            }

            $before = $this->equipmentSnapshot($equipmentItem);

            $checkout = EquipmentCheckout::query()->create([
                'equipment_item_id' => $equipmentItem->id,
                'event_id' => $eventId,
                'staff_id' => $staff->id,
                'shift_id' => $shift?->id,
                'quantity' => $quantity,
                'checked_out_at' => $checkedOutAt,
                'checked_out_by_user_id' => $actor->id,
            ]);

            // A pool is never stored `checked_out`: it is not wholly held by
            // one staff member, and its availability is the derivation
            // EQUIP-016 defines rather than a state anybody writes.
            if (! $pooled) {
                $equipmentItem->forceFill([
                    'status' => EquipmentItem::STATUS_CHECKED_OUT,
                ])->save();
            }

            $equipmentItem = $equipmentItem->refresh();
            $checkout = $checkout->refresh();

            $this->audit->recordForEntity(
                entity: $checkout,
                action: 'equipment.checked_out',
                actorUser: $actor,
                organizationId: $equipmentItem->organization_id,
                eventId: $eventId,
                departmentId: $departmentId,
                before: $before,
                after: $this->checkoutSnapshot($checkout, $equipmentItem),
                sourceContext: AuditEvent::SOURCE_API,
            );

            return new EquipmentCheckoutResult(
                equipmentItem: $equipmentItem,
                checkout: $checkout,
                createdStateChange: true,
            );
        });
    }

    /**
     * @throws EquipmentCheckoutException
     */
    public function returnEquipment(
        EquipmentCheckout $checkout,
        User $actor,
        string $returnCondition,
        ?Carbon $returnedAt = null,
        ?int $quantity = null,
        ?string $reason = null,
    ): EquipmentCheckoutResult {
        if (! in_array($returnCondition, self::RETURN_CONDITIONS, true)) {
            throw EquipmentCheckoutException::invalidReturnCondition();
        }

        $returnedAt ??= Carbon::now();

        return DB::transaction(function () use ($checkout, $actor, $returnCondition, $returnedAt, $quantity, $reason): EquipmentCheckoutResult {
            $checkout = EquipmentCheckout::query()
                ->with(['shift.event', 'shift.department'])
                ->whereKey($checkout->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $equipmentItem = EquipmentItem::query()
                ->with(['event', 'department', 'organization'])
                ->whereKey($checkout->equipment_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canReturnCheckout($actor, $checkout, $equipmentItem)) {
                throw EquipmentCheckoutException::unauthorized();
            }

            if ($checkout->returned_at !== null) {
                if ($checkout->return_condition === $returnCondition) {
                    return new EquipmentCheckoutResult(
                        equipmentItem: $equipmentItem,
                        checkout: $checkout,
                        createdStateChange: false,
                    );
                }

                throw EquipmentCheckoutException::checkoutAlreadyReturned();
            }

            if ($returnedAt->lt($checkout->checked_out_at)) {
                throw EquipmentCheckoutException::returnBeforeCheckout();
            }

            $outstanding = $checkout->quantityOutstanding();
            // A tracked unit comes back whole; a pool may come back in parts,
            // and an unstated quantity means "all of what is still out", which
            // is what a desk taking back everything in front of it means.
            $returning = $equipmentItem->isPooled() ? ($quantity ?? $outstanding) : 1;

            if ($returning < 1) {
                throw EquipmentCheckoutException::invalidQuantity();
            }

            if ($returning > $outstanding) {
                throw EquipmentCheckoutException::returnExceedsOutstanding($outstanding, $returning);
            }

            $before = $this->checkoutSnapshot($checkout, $equipmentItem);
            $returnedTotal = (int) ($checkout->quantity_returned ?? 0) + $returning;
            $closes = $returnedTotal >= (int) ($checkout->quantity ?? 1);

            $checkout->forceFill([
                'quantity_returned' => $returnedTotal,
                // A partial pooled return leaves the checkout open: the desk is
                // still owed the rest, and closing it here would lose that.
                'returned_at' => $closes ? $returnedAt : null,
                'returned_by_user_id' => $closes ? $actor->id : null,
                'return_condition' => $closes ? $returnCondition : null,
            ])->save();

            if ($equipmentItem->isPooled()) {
                $this->adjustPoolForReturn(
                    $equipmentItem,
                    $checkout,
                    $returnCondition,
                    $returning,
                    $actor,
                    $reason,
                );
            } else {
                $equipmentItem->forceFill([
                    'status' => $returnCondition,
                ])->save();
            }

            $checkout = $checkout->refresh();
            $equipmentItem = $equipmentItem->refresh();

            $this->audit->recordForEntity(
                entity: $checkout,
                action: $closes ? 'equipment.returned' : 'equipment.partially_returned',
                actorUser: $actor,
                organizationId: $equipmentItem->organization_id,
                eventId: $checkout->event_id,
                departmentId: $checkout->shift?->department_id ?? $equipmentItem->department_id,
                before: $before,
                after: $this->checkoutSnapshot($checkout, $equipmentItem),
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_API,
            );

            return new EquipmentCheckoutResult(
                equipmentItem: $equipmentItem,
                checkout: $checkout,
                createdStateChange: true,
            );
        });
    }

    /**
     * Write off pooled units that did not come back serviceable (EQUIP-017).
     *
     * A pool has no state to move into — it is a quantity, and half of it being
     * broken is not a fact about the whole record. So the loss lands on
     * `quantity_total`, which is the serviceable count the availability
     * derivation reads, and the adjustment is audited with the reason the
     * operator gave. Units returned in good order change nothing: they were
     * never subtracted from the total, only counted as out.
     */
    private function adjustPoolForReturn(
        EquipmentItem $equipmentItem,
        EquipmentCheckout $checkout,
        string $returnCondition,
        int $returning,
        User $actor,
        ?string $reason,
    ): void {
        if ($returnCondition === EquipmentItem::STATUS_RETURNED) {
            return;
        }

        $before = $this->equipmentSnapshot($equipmentItem);

        $equipmentItem->forceFill([
            'quantity_total' => max(0, (int) $equipmentItem->quantity_total - $returning),
        ])->save();

        $equipmentItem->refresh();

        $this->audit->recordForEntity(
            entity: $equipmentItem,
            action: 'equipment_pool.adjusted',
            actorUser: $actor,
            organizationId: $equipmentItem->organization_id,
            eventId: $checkout->event_id,
            departmentId: $equipmentItem->department_id,
            before: $before,
            after: [
                ...$this->equipmentSnapshot($equipmentItem),
                'adjusted_by' => -$returning,
                'return_condition' => $returnCondition,
                'equipment_checkout_id' => (string) $checkout->id,
            ],
            reason: $reason ?? sprintf('%d unit(s) returned %s.', $returning, $returnCondition),
            sourceContext: AuditEvent::SOURCE_API,
        );
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function authorizeShiftCheckout(User $actor, Shift $shift): void
    {
        if ($shift->event === null || $shift->department === null
            || ! $this->access->canManageEquipment($actor, $shift->event, $shift->department)) {
            throw EquipmentCheckoutException::unauthorized();
        }

        if ($shift->isCancelled()) {
            throw EquipmentCheckoutException::cancelledShift();
        }
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function authorizeDepartmentCheckout(
        User $actor,
        EquipmentItem $equipmentItem,
        ?Event $event,
    ): void {
        if ($event === null) {
            throw EquipmentCheckoutException::noEventContext();
        }

        /*
         * Authority is weighed against the event this handoff is being made
         * under, not against the item's own scope, because department stock has
         * none. An item that does name an event resolves to that event above,
         * so a caller cannot borrow another event's authority by supplying it.
         */
        if ($equipmentItem->department_id === null
            || $equipmentItem->department === null
            || ! $this->access->canManageEquipment($actor, $event, $equipmentItem->department)) {
            throw EquipmentCheckoutException::unauthorized();
        }
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function validateEquipmentShiftScope(EquipmentItem $equipmentItem, Shift $shift): void
    {
        if ($equipmentItem->event_id !== null && (string) $equipmentItem->event_id !== (string) $shift->event_id) {
            throw EquipmentCheckoutException::equipmentOutsideShiftScope();
        }

        if ($equipmentItem->department_id !== null && (string) $equipmentItem->department_id !== (string) $shift->department_id) {
            throw EquipmentCheckoutException::equipmentOutsideShiftScope();
        }
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function ensureRosteredStaff(Shift $shift, Staff $staff): void
    {
        $isRostered = ShiftAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->exists();

        if (! $isRostered) {
            throw EquipmentCheckoutException::staffNotRosteredForShift();
        }
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function ensureStaffInEquipmentDepartment(EquipmentItem $equipmentItem, Staff $staff): void
    {
        if ($equipmentItem->department_id === null) {
            return;
        }

        $inDepartment = DepartmentMembership::query()
            ->active()
            ->where('department_id', $equipmentItem->department_id)
            ->where('staff_id', $staff->id)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->lockForUpdate()
            ->exists();

        if (! $inDepartment) {
            throw EquipmentCheckoutException::staffOutsideDepartment();
        }
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function existingOpenCheckout(
        EquipmentCheckout $checkout,
        EquipmentItem $equipmentItem,
        Staff $staff,
        ?Shift $shift,
    ): EquipmentCheckoutResult {
        $sameStaff = (string) $checkout->staff_id === (string) $staff->id;
        $sameShift = (string) ($checkout->shift_id ?? '') === (string) ($shift?->id ?? '');

        if (! $sameStaff || ! $sameShift) {
            throw EquipmentCheckoutException::openCheckoutForDifferentStaff();
        }

        return new EquipmentCheckoutResult(
            equipmentItem: $equipmentItem,
            checkout: $checkout,
            createdStateChange: false,
        );
    }

    /**
     * Whether this actor may take this checkout back.
     *
     * The event comes from the checkout rather than from the item, which is the
     * same correction the checkout path needed: department stock names no event
     * of its own, so weighing authority against the item's scope refused every
     * return of it — including the ones this service had just accepted. A
     * checkout always records the event it was made under, and that is the
     * event the handoff happened in.
     */
    private function canReturnCheckout(User $actor, EquipmentCheckout $checkout, EquipmentItem $equipmentItem): bool
    {
        if ($checkout->shift !== null) {
            $checkout->shift->loadMissing(['event', 'department']);

            return $checkout->shift->event !== null
                && $checkout->shift->department !== null
                && $this->access->canManageEquipment($actor, $checkout->shift->event, $checkout->shift->department);
        }

        if ($equipmentItem->department_id === null) {
            return false;
        }

        $checkout->loadMissing('event');
        $equipmentItem->loadMissing('department');

        return $checkout->event !== null
            && $equipmentItem->department !== null
            && $this->access->canManageEquipment($actor, $checkout->event, $equipmentItem->department);
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentSnapshot(EquipmentItem $equipmentItem): array
    {
        return [
            'equipment_item_id' => $equipmentItem->id,
            'organization_id' => $equipmentItem->organization_id,
            'event_id' => $equipmentItem->event_id,
            'department_id' => $equipmentItem->department_id,
            'tracking' => $equipmentItem->tracking,
            'quantity_total' => (int) $equipmentItem->quantity_total,
            'status' => $equipmentItem->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutSnapshot(EquipmentCheckout $checkout, EquipmentItem $equipmentItem): array
    {
        return [
            ...$this->equipmentSnapshot($equipmentItem),
            'equipment_checkout_id' => $checkout->id,
            'staff_id' => $checkout->staff_id,
            'shift_id' => $checkout->shift_id,
            // Which of the two EQUIP-009 scopes this handoff was made under,
            // recorded in the audit trail as a word rather than left for a
            // reader to infer from a null shift column.
            'assignment_scope' => $checkout->assignmentScope(),
            'quantity' => (int) ($checkout->quantity ?? 1),
            'quantity_returned' => $checkout->quantity_returned === null
                ? null
                : (int) $checkout->quantity_returned,
            'checked_out_at' => $checkout->checked_out_at?->toIso8601String(),
            'checked_out_by_user_id' => $checkout->checked_out_by_user_id,
            'returned_at' => $checkout->returned_at?->toIso8601String(),
            'returned_by_user_id' => $checkout->returned_by_user_id,
            'return_condition' => $checkout->return_condition,
        ];
    }
}
