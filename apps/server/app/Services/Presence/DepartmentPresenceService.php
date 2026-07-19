<?php

namespace App\Services\Presence;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Permissions\DepartmentOperationalAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DepartmentPresenceService
{
    public function __construct(
        private readonly DepartmentOperationalAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws DepartmentPresenceException
     */
    public function markOnSite(
        Event $event,
        Department $department,
        Staff $staff,
        User $actor,
        ?Carbon $markedAt = null,
    ): DepartmentPresenceResult {
        return $this->setPresenceState(
            event: $event,
            department: $department,
            staff: $staff,
            actor: $actor,
            state: EventDepartmentPresence::STATE_ON_SITE,
            markedAt: $markedAt ?? Carbon::now(),
        );
    }

    /**
     * @throws DepartmentPresenceException
     */
    public function markOffSite(
        Event $event,
        Department $department,
        Staff $staff,
        User $actor,
        ?Carbon $markedAt = null,
    ): DepartmentPresenceResult {
        return $this->setPresenceState(
            event: $event,
            department: $department,
            staff: $staff,
            actor: $actor,
            state: EventDepartmentPresence::STATE_OFF_SITE,
            markedAt: $markedAt ?? Carbon::now(),
        );
    }

    /**
     * @throws DepartmentPresenceException
     */
    private function setPresenceState(
        Event $event,
        Department $department,
        Staff $staff,
        User $actor,
        string $state,
        Carbon $markedAt,
    ): DepartmentPresenceResult {
        return DB::transaction(function () use ($event, $department, $staff, $actor, $state, $markedAt): DepartmentPresenceResult {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $department = Department::query()->whereKey($department->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $department->organization_id !== (string) $event->organization_id) {
                throw DepartmentPresenceException::departmentOutsideEventOrganization();
            }

            if (! $this->access->canManagePresence($actor, $event, $department)) {
                throw DepartmentPresenceException::unauthorized();
            }

            if (! $this->isEligibleDepartmentStaff($department, $staff)) {
                throw DepartmentPresenceException::staffNotEligibleForDepartment();
            }

            $presence = EventDepartmentPresence::query()
                ->where('event_id', $event->id)
                ->where('department_id', $department->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($presence !== null && $presence->current_state === $state) {
                return new DepartmentPresenceResult(
                    presence: $presence,
                    createdStateChange: false,
                );
            }

            if ($state === EventDepartmentPresence::STATE_OFF_SITE) {
                $this->assertMayLeaveSite($event, $department, $staff);
            }

            $before = $presence === null ? null : $this->snapshot($presence);
            $presence ??= new EventDepartmentPresence([
                'event_id' => $event->id,
                'department_id' => $department->id,
                'staff_id' => $staff->id,
            ]);

            $presence->forceFill([
                'current_state' => $state,
                'marked_on_site_at' => $state === EventDepartmentPresence::STATE_ON_SITE
                    ? $markedAt
                    : $presence->marked_on_site_at,
                'marked_off_site_at' => $state === EventDepartmentPresence::STATE_OFF_SITE
                    ? $markedAt
                    : null,
                'last_marked_by_user_id' => $actor->id,
            ])->save();

            $presence = $presence->refresh();

            $this->audit->recordForEntity(
                entity: $presence,
                action: $state === EventDepartmentPresence::STATE_ON_SITE
                    ? 'department_presence.marked_on_site'
                    : 'department_presence.marked_off_site',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $department->id,
                before: $before,
                after: $this->snapshot($presence),
                sourceContext: AuditEvent::SOURCE_API,
            );

            return new DepartmentPresenceResult(
                presence: $presence,
                createdStateChange: true,
            );
        });
    }

    private function isEligibleDepartmentStaff(Department $department, Staff $staff): bool
    {
        return DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * @throws DepartmentPresenceException
     */
    private function assertMayLeaveSite(Event $event, Department $department, Staff $staff): void
    {
        $checkedIn = AttendanceRecord::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->where('current_state', AttendanceRecord::STATE_CHECKED_IN)
            ->exists();

        if ($checkedIn) {
            throw DepartmentPresenceException::checkedInToShift();
        }

        $openEquipment = EquipmentCheckout::query()
            ->where('event_id', $event->id)
            ->where('staff_id', $staff->id)
            ->whereNull('returned_at')
            ->whereHas('equipmentItem', fn ($query) => $query
                ->where(function ($scope) use ($department): void {
                    $scope->whereNull('department_id')
                        ->orWhere('department_id', $department->id);
                })
                ->whereIn('status', [EquipmentItem::STATUS_CHECKED_OUT]))
            ->exists();

        if ($openEquipment) {
            throw DepartmentPresenceException::openEquipmentCheckout();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EventDepartmentPresence $presence): array
    {
        return [
            'event_id' => $presence->event_id,
            'department_id' => $presence->department_id,
            'staff_id' => $presence->staff_id,
            'current_state' => $presence->current_state,
            'marked_on_site_at' => $presence->marked_on_site_at?->toIso8601String(),
            'marked_off_site_at' => $presence->marked_off_site_at?->toIso8601String(),
            'last_marked_by_user_id' => $presence->last_marked_by_user_id,
        ];
    }
}
