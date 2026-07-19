<?php

namespace App\Services\Equipment;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;
use App\Services\Attendance\AttendanceCheckInAccess;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manual MVP equipment checkout/check-in workflow (SLB-011, SLB-012;
 * EQUIP-001 through EQUIP-005). Equipment is checked out to individual staff
 * members; full inventory custody chains and allotments stay future scope.
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
        private readonly AttendanceCheckInAccess $shiftAccess,
        private readonly ApplicationReviewAccess $applicationReviewAccess,
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
    ): EquipmentCheckoutResult {
        $checkedOutAt ??= Carbon::now();

        return DB::transaction(function () use ($equipmentItem, $staff, $actor, $shift, $checkedOutAt): EquipmentCheckoutResult {
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
                $this->authorizeDepartmentCheckout($actor, $equipmentItem);
                $this->ensureStaffInEquipmentDepartment($equipmentItem, $staff);

                $eventId = (string) $equipmentItem->event_id;
                $departmentId = $equipmentItem->department_id === null ? null : (string) $equipmentItem->department_id;
            }

            if ($eventId === '') {
                throw EquipmentCheckoutException::noEventContext();
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

            $before = $this->equipmentSnapshot($equipmentItem);

            $checkout = EquipmentCheckout::query()->create([
                'equipment_item_id' => $equipmentItem->id,
                'event_id' => $eventId,
                'staff_id' => $staff->id,
                'shift_id' => $shift?->id,
                'checked_out_at' => $checkedOutAt,
                'checked_out_by_user_id' => $actor->id,
            ]);

            $equipmentItem->forceFill([
                'status' => EquipmentItem::STATUS_CHECKED_OUT,
            ])->save();

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
    ): EquipmentCheckoutResult {
        if (! in_array($returnCondition, self::RETURN_CONDITIONS, true)) {
            throw EquipmentCheckoutException::invalidReturnCondition();
        }

        $returnedAt ??= Carbon::now();

        return DB::transaction(function () use ($checkout, $actor, $returnCondition, $returnedAt): EquipmentCheckoutResult {
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

            $before = $this->checkoutSnapshot($checkout, $equipmentItem);

            $checkout->forceFill([
                'returned_at' => $returnedAt,
                'returned_by_user_id' => $actor->id,
                'return_condition' => $returnCondition,
            ])->save();

            $equipmentItem->forceFill([
                'status' => $returnCondition,
            ])->save();

            $checkout = $checkout->refresh();
            $equipmentItem = $equipmentItem->refresh();

            $this->audit->recordForEntity(
                entity: $checkout,
                action: 'equipment.returned',
                actorUser: $actor,
                organizationId: $equipmentItem->organization_id,
                eventId: $checkout->event_id,
                departmentId: $checkout->shift?->department_id ?? $equipmentItem->department_id,
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
    private function authorizeShiftCheckout(User $actor, Shift $shift): void
    {
        if (! $this->shiftAccess->canCheckInForShift($actor, $shift)) {
            throw EquipmentCheckoutException::unauthorized();
        }

        if ($shift->isCancelled()) {
            throw EquipmentCheckoutException::cancelledShift();
        }
    }

    /**
     * @throws EquipmentCheckoutException
     */
    private function authorizeDepartmentCheckout(User $actor, EquipmentItem $equipmentItem): void
    {
        if ($equipmentItem->event_id === null) {
            throw EquipmentCheckoutException::noEventContext();
        }

        if ($equipmentItem->department_id === null
            || ! $this->applicationReviewAccess
                ->departmentLeadDepartmentIds($actor)
                ->contains((string) $equipmentItem->department_id)) {
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

    private function canReturnCheckout(User $actor, EquipmentCheckout $checkout, EquipmentItem $equipmentItem): bool
    {
        if ($checkout->shift !== null && $this->shiftAccess->canCheckInForShift($actor, $checkout->shift)) {
            return true;
        }

        if ($equipmentItem->department_id === null) {
            return false;
        }

        return $this->applicationReviewAccess
            ->departmentLeadDepartmentIds($actor)
            ->contains((string) $equipmentItem->department_id);
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
            'checked_out_at' => $checkout->checked_out_at?->toIso8601String(),
            'checked_out_by_user_id' => $checkout->checked_out_by_user_id,
            'returned_at' => $checkout->returned_at?->toIso8601String(),
            'returned_by_user_id' => $checkout->returned_by_user_id,
            'return_condition' => $checkout->return_condition,
        ];
    }
}
