<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Credential\CredentialRevocationService;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The event credential administration surface's two endpoints (M18.5;
 * CRED-009 through CRED-014).
 *
 * `CredentialRevocationTest` covers the domain rules directly against the
 * service. These are about the path to it: who the node lets through, what the
 * list tells somebody before they act, and what the record looks like after.
 */
class EventCredentialAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_reads_what_revocation_would_remove_and_what_it_would_preserve(): void
    {
        Carbon::setTestNow('2026-06-15 18:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);
        $staff = $scenario['staff'];

        $completedShift = $this->createShift($scenario['event'], $scenario['department'], '2026-06-10 09:00:00', '2026-06-10 17:00:00');
        ShiftAssignment::factory()->create([
            'shift_id' => $completedShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        HoursWorked::factory()->create([
            'shift_id' => $completedShift->id,
            'staff_id' => $staff->id,
            'minutes_worked' => 480,
        ]);

        foreach (['2026-06-20 09:00:00', '2026-06-21 09:00:00'] as $start) {
            ShiftAssignment::factory()->create([
                'shift_id' => $this->createShift(
                    $scenario['event'],
                    $scenario['department'],
                    $start,
                    Carbon::parse($start)->addHours(8)->toDateTimeString(),
                )->id,
                'staff_id' => $staff->id,
                'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            ]);
        }

        EventCredential::factory()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $response = $this->actingAsClient($scenario['actor'])
            ->getJson("/api/events/{$scenario['event']->id}/credentials")
            ->assertOk()
            ->assertJsonPath('event_id', (string) $scenario['event']->id)
            ->assertJsonPath('credentials.0.staff_id', (string) $staff->id)
            ->assertJsonPath('credentials.0.status', EventCredential::STATUS_ELIGIBLE)
            ->assertJsonPath('credentials.0.can_revoke', true)
            ->assertJsonPath('credentials.0.revoke_blocked_reason', null)
            // The two halves of CRED-012 and CRED-013, counted before anybody
            // commits to the act that separates them.
            ->assertJsonPath('credentials.0.future_shift_count', 2)
            ->assertJsonPath('credentials.0.completed_shift_count', 1)
            ->assertJsonPath('credentials.0.recorded_minutes', 480)
            ->assertJsonPath('credentials.0.departments', ['Gate']);

        // Identity enough to be sure who is being revoked, and no further: the
        // contact columns REPORT-010 keeps out of the export are not here
        // either, and an IC lead reaching this surface holds no export at all.
        $response->assertJsonMissingPath('credentials.0.email');
        $response->assertJsonMissingPath('credentials.0.phone');
        $response->assertJsonMissingPath('credentials.0.date_of_birth');
    }

    public function test_the_list_carries_a_staff_member_with_shifts_and_no_credential_row(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);

        ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($scenario['event'], $scenario['department'], '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $scenario['staff']->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
        ]);

        // No `event_credentials` row at all, which is a state the command still
        // acts on. A list built from credential rows alone would hide somebody
        // an organizer can and may need to remove from the schedule.
        $this->actingAsClient($scenario['actor'])
            ->getJson("/api/events/{$scenario['event']->id}/credentials")
            ->assertOk()
            ->assertJsonCount(1, 'credentials')
            ->assertJsonPath('credentials.0.staff_id', (string) $scenario['staff']->id)
            ->assertJsonPath('credentials.0.status', null)
            ->assertJsonPath('credentials.0.can_revoke', true)
            ->assertJsonPath('credentials.0.future_shift_count', 1);
    }

    public function test_an_already_revoked_credential_offers_no_revocation(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);

        EventCredential::factory()->revoked()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        $this->actingAsClient($scenario['actor'])
            ->getJson("/api/events/{$scenario['event']->id}/credentials")
            ->assertOk()
            ->assertJsonPath('credentials.0.status', EventCredential::STATUS_REVOKED)
            ->assertJsonPath('credentials.0.status_reason_label', 'Manual revocation')
            ->assertJsonPath('credentials.0.can_revoke', false)
            ->assertJsonPath('credentials.0.revoke_blocked_reason', 'This credential is already revoked.');
    }

    public function test_a_non_organizer_non_ic_lead_is_refused_the_list_and_the_command(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        // A department lead holds `reports.credential_eligibility.export` and
        // can read the same records as a file (REPORT-007). Neither endpoint
        // here is that read, and CRED-011 does not name them.
        $scenario = $this->credentialScenario(grantRole: 'department_lead', eventScoped: false);

        ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($scenario['event'], $scenario['department'], '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $scenario['staff']->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        EventCredential::factory()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['staff']->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->actingAsClient($scenario['actor'])
            ->getJson("/api/events/{$scenario['event']->id}/credentials")
            ->assertForbidden();

        $this->actingAsClient($scenario['actor'])
            ->postJson('/api/commands/revoke-credential', [
                'event_id' => (string) $scenario['event']->id,
                'staff_id' => (string) $scenario['staff']->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to revoke event credentials.');

        $this->assertNull(
            EventCredential::query()
                ->where('event_id', $scenario['event']->id)
                ->where('staff_id', $scenario['staff']->id)
                ->firstOrFail()
                ->revoked_at,
        );
    }

    public function test_ic_lead_may_read_the_list_for_the_event_its_department_commands(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->credentialScenario(grantRole: 'ic_lead', eventScoped: true);

        EventCredential::factory()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['staff']->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->actingAsClient($scenario['actor'])
            ->getJson("/api/events/{$scenario['event']->id}/credentials")
            ->assertOk()
            ->assertJsonPath('credentials.0.staff_id', (string) $scenario['staff']->id);
    }

    public function test_revocation_removes_future_shifts_and_leaves_recorded_hours_standing(): void
    {
        Carbon::setTestNow('2026-06-15 18:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);
        $staff = $scenario['staff'];

        $completedShift = $this->createShift($scenario['event'], $scenario['department'], '2026-06-10 09:00:00', '2026-06-10 17:00:00');
        $completedAssignment = ShiftAssignment::factory()->create([
            'shift_id' => $completedShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        $hours = HoursWorked::factory()->create([
            'shift_id' => $completedShift->id,
            'staff_id' => $staff->id,
            'actual_started_at' => Carbon::parse('2026-06-10 09:02:00'),
            'actual_ended_at' => Carbon::parse('2026-06-10 17:00:00'),
            'minutes_worked' => 478,
        ]);

        $futureAssignment = ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($scenario['event'], $scenario['department'], '2026-06-20 09:00:00', '2026-06-20 17:00:00')->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        EventCredential::factory()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $staff->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->actingAsClient($scenario['actor'])
            ->postJson('/api/commands/revoke-credential', [
                'event_id' => (string) $scenario['event']->id,
                'staff_id' => (string) $staff->id,
                'reason' => 'Asked to leave site.',
            ])
            ->assertOk()
            ->assertJsonPath('credential.staff_id', (string) $staff->id)
            ->assertJsonPath('credential.status', EventCredential::STATUS_REVOKED)
            ->assertJsonPath('credential.status_reason', CredentialRevocationService::REASON_MANUAL_REVOCATION)
            ->assertJsonPath('credential.status_reason_label', 'Manual revocation')
            ->assertJsonPath('credential.can_revoke', false)
            // The schedule ahead is gone; the work already done is untouched.
            ->assertJsonPath('credential.future_shift_count', 0)
            ->assertJsonPath('credential.completed_shift_count', 1)
            ->assertJsonPath('credential.recorded_minutes', 478);

        $this->assertNotNull($futureAssignment->fresh()->removed_at);
        $this->assertNull($completedAssignment->fresh()->removed_at);

        // CRED-013 in the only terms that matter to the person it happened to:
        // the hours record is byte-for-byte what it was, still recorded, still
        // carrying the minutes an export and a credit calculation will read.
        $hours->refresh();
        $this->assertSame(478, $hours->minutes_worked);
        $this->assertSame(HoursWorked::STATUS_RECORDED, $hours->status);
        $this->assertSame('2026-06-10 09:02:00', $hours->actual_started_at->toDateTimeString());
        $this->assertSame('2026-06-10 17:00:00', $hours->actual_ended_at->toDateTimeString());
        $this->assertDatabaseCount('hours_worked', 1);
    }

    public function test_revocation_is_audited_with_its_reason_and_each_shift_it_removed(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);

        $futureAssignment = ShiftAssignment::factory()->create([
            'shift_id' => $this->createShift($scenario['event'], $scenario['department'], '2026-06-10 09:00:00', '2026-06-10 17:00:00')->id,
            'staff_id' => $scenario['staff']->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
        ]);

        $credential = EventCredential::factory()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['staff']->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->actingAsClient($scenario['actor'])
            ->postJson('/api/commands/revoke-credential', [
                'event_id' => (string) $scenario['event']->id,
                'staff_id' => (string) $scenario['staff']->id,
                'reason' => 'Repeated no-shows.',
            ])
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('action', 'event_credential.revoked')
            ->where('entity_id', $credential->id)
            ->firstOrFail();

        $this->assertSame((string) $scenario['actor']->id, (string) $audit->actor_user_id);
        $this->assertSame('Repeated no-shows.', $audit->reason);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame(EventCredential::STATUS_ELIGIBLE, $audit->before_json['status']);
        $this->assertSame(EventCredential::STATUS_REVOKED, $audit->after_json['status']);

        // Each removed assignment is its own entry, so the schedule change is
        // legible without inferring it from the credential.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift_assignment.removed_by_credential_revocation',
            'entity_id' => $futureAssignment->id,
        ]);
    }

    public function test_a_blank_reason_is_recorded_as_no_reason_rather_than_an_empty_one(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);

        EventCredential::factory()->create([
            'event_id' => $scenario['event']->id,
            'staff_id' => $scenario['staff']->id,
            'status' => EventCredential::STATUS_ELIGIBLE,
        ]);

        $this->actingAsClient($scenario['actor'])
            ->postJson('/api/commands/revoke-credential', [
                'event_id' => (string) $scenario['event']->id,
                'staff_id' => (string) $scenario['staff']->id,
                'reason' => '   ',
            ])
            ->assertOk();

        $this->assertNull(
            AuditEvent::query()->where('action', 'event_credential.revoked')->firstOrFail()->reason,
        );
    }

    public function test_a_staff_member_with_no_credential_and_no_shifts_is_refused_rather_than_revoked(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);

        $this->actingAsClient($scenario['actor'])
            ->postJson('/api/commands/revoke-credential', [
                'event_id' => (string) $scenario['event']->id,
                'staff_id' => (string) $scenario['staff']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This staff member has no event credential or shift history to revoke.');

        $this->assertDatabaseCount('event_credentials', 0);
    }

    public function test_both_endpoints_require_a_session(): void
    {
        $scenario = $this->credentialScenario(grantRole: 'organizer', eventScoped: false);

        $this->getJson("/api/events/{$scenario['event']->id}/credentials")->assertUnauthorized();
        $this->postJson('/api/commands/revoke-credential', [
            'event_id' => (string) $scenario['event']->id,
            'staff_id' => (string) $scenario['staff']->id,
        ])->assertUnauthorized();
    }

    /**
     * One event, one department, an actor holding `$grantRole` there, and one
     * subject staff member with active standing in the organization.
     *
     * @return array{event: Event, department: Department, actor: User, staff: Staff}
     */
    private function credentialScenario(string $grantRole, bool $eventScoped): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);

        if ($eventScoped && in_array($grantRole, ['ic_lead', 'ic_operator', 'ic_viewer'], true)) {
            $event->forceFill(['ic_department_id' => $department->id])->save();
        }

        $team = Team::factory()->for($department)->create(['name' => 'Gate Team']);

        $actorStaff = Staff::factory()->create(['legal_name' => 'Avery Organizer']);
        $actorUser = User::factory()->create();
        $actorUser->staffProfiles()->attach($actorStaff->id);

        $actorDepartmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($actorStaff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $actorStaff->id,
            'department_membership_id' => $actorDepartmentMembership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $grantRole)->firstOrFail()->id,
        ]);

        $subjectStaff = Staff::factory()->create([
            'legal_name' => 'Wren Subject',
            'date_of_birth' => '1990-01-15',
        ]);
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($subjectStaff, $department);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $subjectStaff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        return [
            'event' => $event,
            'department' => $department,
            'actor' => $actorUser,
            'staff' => $subjectStaff,
        ];
    }

    private function createShift(
        Event $event,
        Department $department,
        string $startsAt,
        string $endsAt,
    ): Shift {
        $department->loadMissing('defaultTeam');

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'starts_at' => Carbon::parse($startsAt),
            'ends_at' => Carbon::parse($endsAt),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
