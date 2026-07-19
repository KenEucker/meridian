<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldReportPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_own_field_report(): void
    {
        $author = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertTrue($author->can('view', $report));
    }

    public function test_non_author_cannot_view_field_report(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($otherUser->can('view', $report));
    }

    public function test_author_cannot_update_or_delete_own_field_report(): void
    {
        $author = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($author->can('update', $report));
        $this->assertFalse($author->can('delete', $report));
    }

    public function test_author_can_append_own_field_report(): void
    {
        $author = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertTrue($author->can('append', $report));
    }

    public function test_non_author_cannot_append_field_report(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($otherUser->can('append', $report));
    }

    public function test_non_author_cannot_update_or_delete_field_report(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($otherUser->can('update', $report));
        $this->assertFalse($otherUser->can('delete', $report));
    }

    public function test_ic_lead_can_view_event_field_report(): void
    {
        $this->assertIcRoleCanViewEventFieldReport('ic_lead');
    }

    public function test_ic_operator_can_view_event_field_report(): void
    {
        $this->assertIcRoleCanViewEventFieldReport('ic_operator');
    }

    public function test_ic_viewer_can_view_event_field_report(): void
    {
        $this->assertIcRoleCanViewEventFieldReport('ic_viewer');
    }

    public function test_department_lead_cannot_view_others_field_report(): void
    {
        $this->assertNonIcRoleCannotViewOthersFieldReport('department_lead');
    }

    public function test_organizer_cannot_view_others_field_report(): void
    {
        $this->assertNonIcRoleCannotViewOthersFieldReport('organizer');
    }

    public function test_lead_organizer_cannot_view_others_field_report(): void
    {
        $this->assertNonIcRoleCannotViewOthersFieldReport('lead_organizer');
    }

    public function test_shift_lead_cannot_view_others_field_report(): void
    {
        $this->assertNonIcRoleCannotViewOthersFieldReport('shift_lead');
    }

    private function assertIcRoleCanViewEventFieldReport(string $roleCode): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole($roleCode, $event);

        $this->assertTrue($icUser->can('view', $report));
    }

    private function assertNonIcRoleCannotViewOthersFieldReport(string $roleCode): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $viewer = $this->userWithRole($roleCode, $event, $organization, eventScoped: false);

        $this->assertFalse($viewer->can('view', $report));
    }

    public function test_ic_role_for_other_event_cannot_view_field_report(): void
    {
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole('ic_viewer', $otherEvent);

        $this->assertFalse($icUser->can('view', $report));
    }

    public function test_revoked_ic_grant_cannot_view_field_report(): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole('ic_lead', $event, revoked: true);

        $this->assertFalse($icUser->can('view', $report));
    }

    public function test_ic_role_cannot_update_or_delete_others_field_report(): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole('ic_lead', $event);

        $this->assertFalse($icUser->can('update', $report));
        $this->assertFalse($icUser->can('delete', $report));
    }

    public function test_ic_lead_cannot_append_others_field_report(): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole('ic_lead', $event);

        $this->assertFalse($icUser->can('append', $report));
    }

    public function test_ic_operator_cannot_append_others_field_report(): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole('ic_operator', $event);

        $this->assertFalse($icUser->can('append', $report));
    }

    public function test_ic_viewer_cannot_append_others_field_report(): void
    {
        $event = Event::factory()->create();
        $author = User::factory()->create();
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();
        $icUser = $this->userWithEventRole('ic_viewer', $event);

        $this->assertFalse($icUser->can('append', $report));
    }

    public function test_elevated_author_can_append_own_field_report(): void
    {
        $event = Event::factory()->create();
        $author = $this->userWithEventRole('ic_lead', $event);
        $report = FieldReport::factory()->forEvent($event)->forAuthor($author)->create();

        $this->assertTrue($author->can('append', $report));
    }

    private function userWithEventRole(string $roleCode, Event $event, bool $revoked = false): User
    {
        return $this->userWithRole($roleCode, $event, $event->organization, eventScoped: true, revoked: $revoked);
    }

    private function userWithRole(
        string $roleCode,
        Event $event,
        Organization $organization,
        bool $eventScoped,
        bool $revoked = false,
    ): User {
        $department = $eventScoped && in_array($roleCode, ['ic_lead', 'ic_operator', 'ic_viewer'], true)
            ? $this->ensureEventIcDepartment($event, $organization)
            : Department::factory()->for($organization)->create();

        if (in_array($roleCode, ['organizer', 'lead_organizer'], true)) {
            $organization->forceFill(['organizers_department_id' => $department->id])->save();
        }

        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $grantAttributes = [
            'team_id' => $team->id,
            'event_id' => $eventScoped ? $event->id : null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ];

        if ($revoked) {
            TeamGrant::factory()->revoked()->create($grantAttributes);
        } else {
            TeamGrant::factory()->create($grantAttributes);
        }

        return $user;
    }

    private function ensureEventIcDepartment(Event $event, Organization $organization): Department
    {
        if ($event->ic_department_id !== null) {
            return Department::query()->findOrFail($event->ic_department_id);
        }

        $event->loadMissing(['icDepartment', 'organization.defaultIcDepartment']);

        $department = $event->organization?->defaultIcDepartment;

        if ($department !== null) {
            return $department;
        }

        $department = Department::factory()->for($organization)->create();
        $event->forceFill(['ic_department_id' => $department->id])->save();

        return $department;
    }
}
