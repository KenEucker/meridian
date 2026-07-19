<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Presence\DepartmentPresenceException;
use App\Services\Presence\DepartmentPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_presence_table_has_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('event_department_presences'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'staff_id',
            'current_state',
            'marked_on_site_at',
            'marked_off_site_at',
            'last_marked_by_user_id',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('event_department_presences', $column),
                "event_department_presences.{$column} missing",
            );
        }
    }

    public function test_department_logistics_can_mark_active_department_staff_on_site(): void
    {
        [$event, $department, $staff, $logistics] = $this->presenceScenario();
        $markedAt = Carbon::parse('2026-07-01 07:30:00');

        $result = app(DepartmentPresenceService::class)->markOnSite(
            event: $event,
            department: $department,
            staff: $staff,
            actor: $logistics,
            markedAt: $markedAt,
        );

        $this->assertTrue($result->createdStateChange);
        $this->assertSame(EventDepartmentPresence::STATE_ON_SITE, $result->presence->current_state);
        $this->assertTrue($result->presence->marked_on_site_at->equalTo($markedAt));
        $this->assertSame($logistics->id, $result->presence->last_marked_by_user_id);

        $audit = AuditEvent::query()
            ->where('action', 'department_presence.marked_on_site')
            ->where('entity_id', $result->presence->id)
            ->firstOrFail();

        $this->assertSame($logistics->id, $audit->actor_user_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame(EventDepartmentPresence::STATE_ON_SITE, $audit->after_json['current_state']);

        $repeat = app(DepartmentPresenceService::class)->markOnSite(
            event: $event,
            department: $department,
            staff: $staff,
            actor: $logistics,
            markedAt: Carbon::parse('2026-07-01 07:35:00'),
        );

        $this->assertFalse($repeat->createdStateChange);
        $this->assertDatabaseCount('event_department_presences', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'department_presence.marked_on_site')->count());
    }

    public function test_non_logistics_user_cannot_mark_department_presence(): void
    {
        [$event, $department, $staff] = $this->presenceScenario();

        $this->expectException(DepartmentPresenceException::class);
        $this->expectExceptionMessage('You are not authorized to manage department presence.');

        app(DepartmentPresenceService::class)->markOnSite(
            event: $event,
            department: $department,
            staff: $staff,
            actor: User::factory()->create(),
        );
    }

    public function test_staff_must_be_active_department_member_to_be_marked_on_site(): void
    {
        [$event, $department, , $logistics] = $this->presenceScenario();
        $outsideStaff = Staff::factory()->create();

        $this->expectException(DepartmentPresenceException::class);
        $this->expectExceptionMessage('Staff must be an active department member before being marked on-site.');

        app(DepartmentPresenceService::class)->markOnSite(
            event: $event,
            department: $department,
            staff: $outsideStaff,
            actor: $logistics,
        );
    }

    public function test_staff_cannot_leave_site_while_checked_in_or_holding_equipment(): void
    {
        [$event, $department, $staff, $logistics] = $this->presenceScenario();
        $shift = $this->shiftFor($event, $department, $staff);

        app(DepartmentPresenceService::class)->markOnSite($event, $department, $staff, $logistics);

        AttendanceRecord::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => Carbon::parse('2026-07-01 08:00:00'),
            'checked_out_at' => null,
        ]);

        try {
            app(DepartmentPresenceService::class)->markOffSite($event, $department, $staff, $logistics);

            $this->fail('Checked-in staff should not be marked off-site.');
        } catch (DepartmentPresenceException $exception) {
            $this->assertSame(
                'Staff must be checked out from department shifts before being marked off-site.',
                $exception->getMessage(),
            );
        }

        AttendanceRecord::query()->update([
            'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
            'checked_out_at' => Carbon::parse('2026-07-01 12:00:00'),
        ]);

        $equipment = EquipmentItem::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'department_id' => $department->id,
            'status' => EquipmentItem::STATUS_CHECKED_OUT,
        ]);
        EquipmentCheckout::factory()->create([
            'equipment_item_id' => $equipment->id,
            'event_id' => $event->id,
            'staff_id' => $staff->id,
            'shift_id' => null,
            'returned_at' => null,
        ]);

        try {
            app(DepartmentPresenceService::class)->markOffSite($event, $department, $staff, $logistics);

            $this->fail('Staff holding equipment should not be marked off-site.');
        } catch (DepartmentPresenceException $exception) {
            $this->assertSame(
                'Staff must return or resolve checked-out department equipment before being marked off-site.',
                $exception->getMessage(),
            );
        }

        EquipmentCheckout::query()->update([
            'returned_at' => Carbon::parse('2026-07-01 12:10:00'),
            'return_condition' => EquipmentItem::STATUS_RETURNED,
        ]);
        $equipment->forceFill(['status' => EquipmentItem::STATUS_RETURNED])->save();

        $result = app(DepartmentPresenceService::class)->markOffSite($event, $department, $staff, $logistics);

        $this->assertTrue($result->createdStateChange);
        $this->assertSame(EventDepartmentPresence::STATE_OFF_SITE, $result->presence->current_state);
    }

    /**
     * @return array{0: Event, 1: Department, 2: Staff, 3: User}
     */
    private function presenceScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);

        return [$event, $department, $staff, $this->departmentRoleUserFor($department, 'department_logistics')];
    }

    private function shiftFor(Event $event, Department $department, Staff $staff): Shift
    {
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'removed_at' => null,
        ]);

        return $shift;
    }

    private function departmentRoleUserFor(Department $department, string $roleCode): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Operators']);
        $departmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role($roleCode)->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
