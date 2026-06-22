<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShiftSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_assignments_table_has_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('shift_assignments'));

        foreach ([
            'id',
            'shift_id',
            'staff_id',
            'assigned_by_user_id',
            'assignment_status',
            'created_at',
            'updated_at',
            'removed_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('shift_assignments', $column),
                "shift_assignments.{$column} missing",
            );
        }
    }

    public function test_eligible_staff_can_sign_up_immediately(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();

        $assignment = app(ShiftSignupService::class)->signUp($shift, $staff, $user);

        $this->assertSame((string) $shift->id, (string) $assignment->shift_id);
        $this->assertSame((string) $staff->id, (string) $assignment->staff_id);
        $this->assertNull($assignment->assigned_by_user_id);
        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $assignment->assignment_status);
        $this->assertNull($assignment->removed_at);
        $this->assertTrue($assignment->isSelfSignup());

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.signed_up')
            ->where('entity_id', $assignment->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertSame($user->id, $audit->actor_user_id);
    }

    public function test_signup_rejects_cancelled_shift(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $shift->forceFill(['cancelled_at' => now()])->save();

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Cancelled shifts do not accept signup.');

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);
    }

    public function test_signup_rejects_closed_signup_window(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $shift->forceFill([
            'signup_opens_at' => now()->addDay(),
            'signup_closes_at' => now()->addWeek(),
        ])->save();

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Shift signup is not currently open.');

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);
    }

    public function test_signup_rejects_staff_not_on_eligible_team(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $otherTeam = Team::factory()->for($shift->department)->create(['code' => 'OTHER']);
        $shift->forceFill(['eligible_team_id' => $otherTeam->id])->save();

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Staff must belong to the shift eligible team before signup.');

        app(ShiftSignupService::class)->signUp($shift->refresh(), $staff, $user);
    }

    public function test_signup_rejects_staff_without_department_membership(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        DepartmentMembership::query()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->update(['archived_at' => now()]);

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Staff must belong to the shift department before signup.');

        app(ShiftSignupService::class)->signUp($shift, $staff, $user);
    }

    public function test_signup_rejects_duplicate_active_assignment(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $service = app(ShiftSignupService::class);

        $service->signUp($shift, $staff, $user);

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('This staff member is already signed up for the shift.');

        $service->signUp($shift->refresh(), $staff->refresh(), $user);
    }

    public function test_signup_rejects_staff_profile_not_linked_to_user(): void
    {
        [$shift, $staff] = $this->eligibleSignupScenario(returnUser: false);
        $otherUser = User::factory()->create();

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('The staff profile must belong to the signing-up user.');

        app(ShiftSignupService::class)->signUp($shift, $staff, $otherUser);
    }

    public function test_signup_rejects_do_not_staff_organization_status(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();

        StaffOrganizationStatus::query()->updateOrCreate(
            [
                'organization_id' => $shift->event->organization_id,
                'staff_id' => $staff->id,
            ],
            [
                'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
                'status_reason' => 'Blocked by organizer.',
                'status_changed_at' => now(),
            ],
        );

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Do Not Staff records cannot sign up for shifts.');

        app(ShiftSignupService::class)->signUp($shift, $staff, $user);
    }

    public function test_signup_respects_open_signup_window_at_signup_moment(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $opensAt = Carbon::parse('2026-07-01 09:00:00');
        $shift->forceFill([
            'signup_opens_at' => $opensAt,
            'signup_closes_at' => $opensAt->copy()->addDays(7),
        ])->save();

        $assignment = app(ShiftSignupService::class)->signUp(
            $shift->refresh(),
            $staff,
            $user,
            $opensAt->copy()->addHour(),
        );

        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $assignment->assignment_status);
    }

    /**
     * @return array{0: Shift, 1: Staff, 2?: User}
     */
    private function eligibleSignupScenario(bool $returnUser = true): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Lead',
            'signup_opens_at' => null,
            'signup_closes_at' => null,
        ]);

        if ($returnUser) {
            return [$shift, $staff, $user];
        }

        return [$shift, $staff];
    }
}
