<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Exceptions\FieldReportAcceptanceException;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\FieldReports\FieldReportAcceptanceService;
use App\Services\FieldReports\FieldReportOnBehalfAccess;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Field Reports taken on behalf of another staff member (M18.24A; FR-015,
 * FR-016, FR-017; requirements 4.8A; technical spec 17.3, 17.4).
 *
 * The client has had a dictation surface since M18.9 with no requirement behind
 * it, no server authorization, and no scoping on its staff directory. This is
 * the server side, and the three things it has to get right are the three
 * requirements: who may take a report, whose name they may put on it, and what
 * taking it does *not* give them.
 */
class DictatedFieldReportTest extends TestCase
{
    use RefreshDatabase;

    /** An Operator may take a report, and both people end up on the record (FR-015). */
    public function test_an_operator_takes_a_report_recording_author_and_submitter_separately(): void
    {
        $world = $this->world();
        $operator = $this->operatorFor($world['department'], $world['event']);

        $report = app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $operator, $world['reporter']),
        );

        // The reporting staff member is the author; the Operator is the
        // submitter. Neither is rewritten to look like the other, because both
        // facts are true and an export needs to be able to tell them apart.
        $this->assertSame((string) $world['reporter']->id, (string) $report->staff_id);
        $this->assertSame((string) $operator->id, (string) $report->submitted_by_user_id);
        $this->assertTrue($report->wasTakenOnBehalf());
    }

    /**
     * The submitter gains no append authority from having taken the report
     * (FR-016).
     *
     * This is the one that would be easy to get wrong, because the submitter is
     * the obvious "author" if you read only the column name. An operator who
     * spent a shift at a radio would end up able to add to every account they
     * had transcribed.
     */
    public function test_the_submitter_gains_no_append_authority(): void
    {
        $world = $this->world();
        $operator = $this->operatorFor($world['department'], $world['event']);

        $report = app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $operator, $world['reporter']),
        );

        $reportingUser = $world['reporter']->users()->firstOrFail();

        $this->assertFalse($operator->can('append', $report));
        $this->assertTrue($reportingUser->can('append', $report));

        // Nor may they attach a photograph to somebody else's account: they
        // were not at the scene, they were at a radio.
        $this->assertFalse($operator->can('uploadPhoto', $report));
        $this->assertTrue($reportingUser->can('uploadPhoto', $report));

        // Reading it is a different matter — they typed it — and so is the
        // author reading their own account.
        $this->assertTrue($operator->can('view', $report));
        $this->assertTrue($reportingUser->can('view', $report));
    }

    /** An unauthorized user cannot take a report for anyone (FR-015). */
    public function test_an_unauthorized_user_cannot_take_a_report_for_anyone(): void
    {
        $world = $this->world();
        // A department lead is senior, works the same department, and still may
        // not file under one of their staff members' names. FR-015 names three
        // roles and this is not one of them.
        $lead = $this->userWithDepartmentRole(
            $world['department'],
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
        );

        $this->expectException(FieldReportAcceptanceException::class);
        $this->expectExceptionMessage('not authorized to take a report for them');

        app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $lead, $world['reporter']),
        );
    }

    /**
     * A Department Operator may only name their own department's staff
     * (FR-017; requirements 4.8A).
     */
    public function test_an_operator_cannot_take_a_report_for_staff_outside_their_department(): void
    {
        $world = $this->world();
        $operator = $this->operatorFor($world['department'], $world['event']);

        $this->expectException(FieldReportAcceptanceException::class);
        $this->expectExceptionMessage('not authorized to take a report for them');

        app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $operator, $world['outsider']),
        );
    }

    /**
     * The staff selector discloses no staff outside the creating user's scope
     * (FR-017).
     *
     * Computed rather than filtered on the way out: a picker that lists
     * everybody and refuses on submit has already disclosed the roster, which
     * is the disclosure this requirement exists to prevent.
     */
    public function test_the_staff_selector_discloses_no_staff_outside_scope(): void
    {
        $world = $this->world();
        $operator = $this->operatorFor($world['department'], $world['event']);

        $selectable = app(FieldReportOnBehalfAccess::class)
            ->selectableStaffOptions($operator, $world['event']);

        $ids = array_column($selectable, 'staff_id');

        $this->assertContains((string) $world['reporter']->id, $ids);
        $this->assertNotContains((string) $world['outsider']->id, $ids);

        $payload = $this->actingAsClient($operator)
            ->getJson("/api/events/{$world['event']->id}/field-report-dictation")
            ->assertOk()
            ->json();

        $this->assertSame($ids, array_column($payload['staff'], 'staff_id'));
        $this->assertStringNotContainsString(
            (string) $world['outsider']->id,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Incident Command reaches the event, not one department (FR-015, FR-017).
     *
     * An `ic_operator` already reads every Field Report in the event, so
     * narrowing their picker to one department would leave Incident Command
     * unable to take a report from whoever is actually on the radio while
     * disclosing nothing they could not already see.
     */
    public function test_an_ic_operator_may_take_a_report_across_the_event(): void
    {
        $world = $this->world();
        $icOperator = $this->icOperatorFor($world['event'], $world['icDepartment']);

        $selectable = app(FieldReportOnBehalfAccess::class)
            ->selectableStaffOptions($icOperator, $world['event']);
        $ids = array_column($selectable, 'staff_id');

        $this->assertContains((string) $world['reporter']->id, $ids);
        $this->assertContains((string) $world['outsider']->id, $ids);

        $report = app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $icOperator, $world['outsider']),
        );

        $this->assertSame((string) $world['outsider']->id, (string) $report->staff_id);
    }

    /** A caller with no taking authority is refused rather than shown an empty picker. */
    public function test_the_dictation_directory_refuses_a_caller_without_taking_authority(): void
    {
        $world = $this->world();
        $ordinary = $this->userForStaff($world['reporter']);

        $this->actingAsClient($ordinary)
            ->getJson("/api/events/{$world['event']->id}/field-report-dictation")
            ->assertForbidden();
    }

    /** An ordinary self-filed report is unchanged by any of this. */
    public function test_a_self_filed_report_still_accepts_and_stays_appendable_by_its_author(): void
    {
        $world = $this->world();
        $reportingUser = $world['reporter']->users()->firstOrFail();

        $report = app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $reportingUser, $world['reporter']),
        );

        $this->assertFalse($report->wasTakenOnBehalf());
        $this->assertTrue($reportingUser->can('append', $report));
    }

    /** A taken report is in the author's own list, not only the submitter's (FR-015). */
    public function test_a_taken_report_reaches_the_author_own_list(): void
    {
        $world = $this->world();
        $operator = $this->operatorFor($world['department'], $world['event']);

        $report = app(FieldReportAcceptanceService::class)->accept(
            $this->submission($world, $operator, $world['reporter']),
        );

        $reportingUser = $world['reporter']->users()->firstOrFail();

        $this->assertTrue(
            FieldReport::query()->forAuthor($reportingUser)->whereKey($report->id)->exists(),
        );
        $this->assertTrue(
            FieldReport::query()->forAuthor($operator)->whereKey($report->id)->exists(),
        );
    }

    /**
     * @return array{
     *     organization: Organization,
     *     event: Event,
     *     department: Department,
     *     icDepartment: Department,
     *     otherDepartment: Department,
     *     reporter: Staff,
     *     outsider: Staff,
     *     device: Device,
     *     node: Node
     * }
     */
    private function world(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $otherDepartment = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $icDepartment = Department::factory()->for($organization)->create(['name' => 'Incident Command']);

        foreach ([$department, $otherDepartment, $icDepartment] as $participating) {
            $event->departmentAssignments()->create(['department_id' => $participating->id]);
        }

        $event->forceFill(['ic_department_id' => $icDepartment->id])->save();

        $reporter = Staff::factory()->create();
        $outsider = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($reporter, $department);
        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($outsider, $otherDepartment);

        $reporter->users()->attach(User::factory()->create()->id);
        $outsider->users()->attach(User::factory()->create()->id);

        return [
            'organization' => $organization,
            'event' => $event->refresh(),
            'department' => $department->refresh(),
            'icDepartment' => $icDepartment,
            'otherDepartment' => $otherDepartment,
            'reporter' => $reporter,
            'outsider' => $outsider,
            'device' => Device::factory()->create(),
            'node' => Node::factory()->create([
                'organization_id' => $organization->id,
                'event_id' => $event->id,
            ]),
        ];
    }

    /**
     * A submission from `$submitter`'s device naming `$author` as the reporting
     * staff member.
     *
     * @param  array<string, mixed>  $world
     * @return array<string, mixed>
     */
    private function submission(array $world, User $submitter, Staff $author): array
    {
        DeviceTrust::factory()->create([
            'user_id' => $submitter->id,
            'device_id' => $world['device']->id,
        ]);

        return [
            'id' => (string) Str::uuid(),
            'event_id' => (string) $world['event']->id,
            'department_id' => null,
            'team_id' => null,
            'submitted_by_user_id' => (string) $submitter->id,
            'staff_id' => (string) $author->id,
            'temporary_local_number' => 'LOCAL-'.strtoupper(Str::random(8)),
            'title' => 'Medical assist near Gate A',
            'body' => 'Field Report filled out by the operator on behalf of the reporting staff member.',
            'device_submitted_at' => '2027-07-04T13:22:10Z',
            'origin_device_id' => (string) $world['device']->id,
            'origin_node_id' => (string) $world['node']->id,
        ];
    }

    private function operatorFor(Department $department, Event $event): User
    {
        return $this->userWithDepartmentRole(
            $department,
            PermissionCatalog::ROLE_DEPARTMENT_OPERATOR,
        );
    }

    /**
     * An `ic_operator` resolves only through the event's Incident Command
     * Department, which is why the grant hangs on a team in it.
     */
    private function icOperatorFor(Event $event, Department $icDepartment): User
    {
        return $this->userWithDepartmentRole(
            $icDepartment,
            PermissionCatalog::ROLE_IC_OPERATOR,
            $event,
        );
    }

    private function userWithDepartmentRole(
        Department $department,
        string $roleCode,
        ?Event $event = null,
    ): User {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($department)->create([
            'name' => $department->name.' '.$roleCode,
        ]);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', $roleCode)
                ->firstOrFail()
                ->id,
            'event_id' => $event?->id,
        ]);

        return $user;
    }

    private function userForStaff(Staff $staff): User
    {
        return $staff->users()->firstOrFail();
    }
}
