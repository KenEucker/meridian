<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Node;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Application\EventApplicationService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Membership\TeamMembershipService;
use App\Services\Notifications\NotificationType;
use App\Services\Shift\ShiftAdminService;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The transactional notification path (M18.21; NOTIFY-001 through NOTIFY-009;
 * BRAND-002).
 *
 * The three cases the milestone names explicitly are here by name: a Do Not
 * Staff auto-rejection sends nothing, an unverified address receives nothing,
 * and a delivery failure does not roll back the operation that caused it.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_approved_application_notifies_the_applicant_with_organization_identity(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective Inc',
            'branding_display_name' => 'Northwood',
        ]);
        $event = Event::factory()->for($organization)->create(['name' => 'Autumn Gathering']);
        $reviewer = User::factory()->create();

        $application = EventApplication::factory()->for($event)->for($organization)->create([
            'status' => EventApplication::STATUS_SUBMITTED,
            'applicant_email' => 'applicant@example.test',
            'applicant_legal_name' => 'Robin Hale',
        ]);

        app(EventApplicationService::class)->approve($application, $reviewer);

        $delivery = NotificationDelivery::query()
            ->ofType(NotificationType::ApplicationApproved)
            ->firstOrFail();

        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('applicant@example.test', $delivery->recipient_email);
        $this->assertSame(EventApplication::class, $delivery->subject_entity_type);
        $this->assertSame((string) $application->id, $delivery->subject_entity_id);

        // BRAND-002: system-generated email carries the organization's identity
        // rather than Meridian's, and NOTIFY-004 names the organization and the
        // event the notification concerns.
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return $mail->hasTo('applicant@example.test')
                && str_contains($mail->notification->subject, 'Northwood')
                && $mail->notification->senderName() === 'Northwood'
                && in_array('Autumn Gathering', $mail->notification->facts, true);
        });
    }

    /** NOTIFY-002. */
    public function test_a_do_not_staff_auto_rejected_application_sends_nothing(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $staff = Staff::factory()->create(['email' => 'blocked@example.test']);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        $application = app(EventApplicationService::class)->submit(
            $event,
            'Blocked Applicant',
            'blocked@example.test',
        );

        $this->assertSame(EventApplication::STATUS_AUTO_REJECTED_DNS, $application->status);

        // Nothing sent, and — just as important — no record of a notification
        // having been considered, since a record naming this application beside
        // a rejection type is itself a disclosure of the Do Not Staff match.
        Mail::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    /** NOTIFY-005. */
    public function test_an_unverified_address_receives_nothing_and_is_recorded_as_unaddressable(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = Staff::factory()->create();
        $unverified = User::factory()->unverified()->create();
        $staff->users()->attach($unverified);

        app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        Mail::assertNothingSent();

        $delivery = NotificationDelivery::query()
            ->ofType(NotificationType::DepartmentMembershipAdded)
            ->firstOrFail();

        $this->assertSame(NotificationDelivery::STATUS_NO_VERIFIED_ADDRESS, $delivery->status);
        $this->assertSame('', $delivery->recipient_email);
    }

    /**
     * NOTIFY-001A: the pair that always happens together is one message naming
     * both, and a later team addition gets its own.
     */
    public function test_department_and_team_addition_collapse_to_one_notification(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = $this->verifiedStaff();
        $assigner = $this->departmentAssigner($department);

        app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        $this->assertSame(1, NotificationDelivery::query()->count());

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationType::DepartmentMembershipAdded->value, $delivery->notification_type);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($department, $team): bool {
            return ($mail->notification->facts['Department'] ?? null) === $department->name
                && ($mail->notification->facts['Team'] ?? null) === $team->name;
        });

        $secondTeam = Team::factory()->for($department)->create(['name' => 'Night Watch']);

        app(TeamMembershipService::class)->assignStaffToTeam($staff, $secondTeam, $assigner);

        $this->assertSame(1, NotificationDelivery::query()
            ->ofType(NotificationType::TeamMembershipAdded)
            ->count());
    }

    /** NOTIFY-009, the per-organization half. */
    public function test_a_suppressed_organization_records_the_notification_and_sends_nothing(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $organization->forceFill(['notifications_suppressed_at' => now()])->save();

        $staff = $this->verifiedStaff();

        app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        Mail::assertNothingSent();

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertStringContainsString('organization', (string) $delivery->outcome_reason);
    }

    /** NOTIFY-009, the global half. */
    public function test_the_global_suppression_switch_sends_nothing(): void
    {
        Mail::fake();
        config()->set('meridian.notifications.suppressed', true);

        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = $this->verifiedStaff();

        app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        Mail::assertNothingSent();

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertStringContainsString('deployment', (string) $delivery->outcome_reason);
    }

    /** NOTIFY-008. */
    public function test_an_onsite_node_holds_the_notification_for_central(): void
    {
        Mail::fake();

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = $this->verifiedStaff();

        app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        Mail::assertNothingSent();

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_HELD_FOR_CENTRAL, $delivery->status);
    }

    /** NOTIFY-006. */
    public function test_a_delivery_failure_does_not_roll_back_the_operation_that_caused_it(): void
    {
        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = $this->verifiedStaff();

        // A transport that refuses everything. The membership must still exist
        // afterwards, and the delivery must record what happened.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('The mail server refused the message.'));

        $membership = app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        $this->assertTrue($membership->exists);
        $this->assertDatabaseHas('department_memberships', [
            'id' => $membership->id,
            'staff_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('team_memberships', [
            'staff_id' => $staff->id,
            'team_id' => $team->id,
        ]);

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('refused', (string) $delivery->outcome_reason);
    }

    /** NOTIFY-001: shift cancelled, to everybody signed up for it. */
    public function test_cancelling_a_shift_notifies_every_active_assignee(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $event = Event::factory()->for($organization)->create(['name' => 'Autumn Gathering']);
        $lead = $this->departmentAssigner($department);

        $shift = Shift::factory()->for($event)->for($department)->create([
            'eligible_team_id' => $team->id,
            'title' => 'Gate Evening',
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(6),
            'capacity' => null,
        ]);

        foreach (range(1, 2) as $ignored) {
            $staff = $this->verifiedStaff();
            app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);
            app(ShiftSignupService::class)->signUp($shift, $staff, $staff->users()->firstOrFail());
        }

        NotificationDelivery::query()->delete();

        app(ShiftAdminService::class)->cancel($shift->refresh(), $lead);

        $this->assertSame(2, NotificationDelivery::query()
            ->ofType(NotificationType::ShiftCancelled)
            ->where('status', NotificationDelivery::STATUS_SENT)
            ->count());

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return ($mail->notification->facts['Shift'] ?? null) === 'Gate Evening';
        });
    }

    /** NOTIFY-007: the record answers whether a person was told, and holds no body. */
    public function test_a_delivery_records_the_recipient_type_subject_and_outcome_without_a_body(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = $this->verifiedStaff();

        $membership = app(DepartmentMembershipService::class)->createWithTeams($staff, $department, [$team]);

        $delivery = NotificationDelivery::query()->firstOrFail();

        $this->assertSame((string) $membership->id, $delivery->subject_entity_id);
        $this->assertSame((string) $staff->id, (string) $delivery->recipient_staff_id);
        $this->assertNotNull($delivery->sent_at);

        $stored = json_encode($delivery->context_json);
        $this->assertStringNotContainsString('You have been added to', (string) $stored);
        $this->assertStringNotContainsString('Open your staff record', (string) $stored);

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => NotificationDelivery::class,
            'entity_id' => (string) $delivery->id,
            'action' => 'notification.sent',
        ]);
    }

    /**
     * @return array{0: Organization, 1: Department, 2: Team}
     */
    private function departmentScaffold(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $team = Team::factory()->for($department)->create(['name' => 'Gate Crew', 'is_default' => true]);

        $department->forceFill(['default_team_id' => $team->id])->save();

        return [$organization, $department->refresh(), $team];
    }

    private function verifiedStaff(): Staff
    {
        $staff = Staff::factory()->create();
        $staff->users()->attach(User::factory()->create());

        return $staff;
    }

    /** A department lead, which is who may add somebody to a further team. */
    private function departmentAssigner(Department $department): User
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $leadStaff = Staff::factory()->create();
        $user->staffProfiles()->attach($leadStaff->id);

        $leadTeam = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $membership = DepartmentMembership::factory()->for($department)->for($leadStaff)->create();

        TeamMembership::factory()->create([
            'team_id' => $leadTeam->id,
            'staff_id' => $leadStaff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $leadTeam->id,
            'permission_role_id' => PermissionRole::query()->where('code', 'department_lead')->firstOrFail()->id,
        ]);

        return $user;
    }
}
