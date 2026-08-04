<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Console\ConfigurationReadinessCheck;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\SignedNodeOperation;
use App\Services\Notifications\NotificationOperationApplier;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\OutstandingRequirementSweep;
use App\Services\Shift\ShiftRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two halves of M18.21 that do not hang off a write path: the scheduled
 * sweep for outstanding acknowledgments and waivers (NOTIFY-001), and the
 * on-site handoff to central (NOTIFY-008). Plus the God Mode visibility
 * NOTIFY-009 requires of the suppression switch.
 */
class NotificationSweepAndSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sweep_notifies_staff_of_an_outstanding_acknowledgment_once(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $staff = $this->verifiedStaff();

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        $document = PolicyDocument::factory()->for($organization)->create([
            'title' => 'Radio Discipline',
            'state' => PolicyDocument::STATE_PUBLISHED,
        ]);

        DocumentAcknowledgmentRequirement::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => $document->id,
            'active' => true,
        ]);

        $first = app(OutstandingRequirementSweep::class)->run();
        $this->assertSame(1, $first['acknowledgments']);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return ($mail->notification->facts['Document'] ?? null) === 'Radio Discipline';
        });

        // Idempotent through the delivery records themselves: a requirement
        // that stays outstanding produces one email, not one a day.
        $second = app(OutstandingRequirementSweep::class)->run();
        $this->assertSame(0, $second['acknowledgments']);
        $this->assertSame(1, NotificationDelivery::query()
            ->ofType(NotificationType::DocumentAcknowledgmentOutstanding)
            ->count());
    }

    public function test_the_sweep_reports_an_expired_waiver_as_expired(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $event = Event::factory()->for($organization)->create();
        $staff = $this->verifiedStaff();

        DepartmentMembership::factory()->for($department)->for($staff)->create();

        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'name' => 'General Liability Waiver',
            'expires_after_days' => 365,
        ]);

        $shift = Shift::factory()->for($event)->for($department)->create([
            'eligible_team_id' => $team->id,
            'starts_at' => Carbon::now()->addDays(5),
            'ends_at' => Carbon::now()->addDays(5)->addHours(4),
        ]);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);

        $shift->assignments()->create([
            'staff_id' => $staff->id,
            'assignment_status' => 'signed_up',
        ]);

        WaiverCompletion::factory()->create([
            'waiver_id' => $waiver->id,
            'staff_id' => $staff->id,
            'completed_at' => Carbon::now()->subYears(2),
            'expires_at' => Carbon::now()->subYear(),
        ]);

        $result = app(OutstandingRequirementSweep::class)->run();

        $this->assertSame(1, $result['waivers']);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
            return ($mail->notification->facts['Status'] ?? null) === 'Expired'
                && str_contains($mail->notification->heading, 'expired');
        });
    }

    /** NOTIFY-008: on-site holds, central sends what the operation names. */
    public function test_central_sends_a_notification_an_onsite_node_handed_over(): void
    {
        Mail::fake();

        [$organization, $department, $team] = $this->departmentScaffold();
        $staff = $this->verifiedStaff();
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $onsite = Node::factory()->create(['node_role' => Node::ROLE_ONSITE]);
        $actor = User::factory()->create();

        $operation = $this->signedOperation(
            originNode: $onsite,
            actor: $actor,
            operationType: NotificationType::DepartmentMembershipAdded->operationType(),
            entityType: DepartmentMembership::class,
            entityId: (string) $membership->id,
        );

        // Central is what this node is, and it holds the membership row.
        Node::factory()->create(['node_role' => Node::ROLE_CENTRAL, 'is_local' => true]);

        app(NotificationOperationApplier::class)->apply($operation);

        $delivery = NotificationDelivery::query()
            ->ofType(NotificationType::DepartmentMembershipAdded)
            ->firstOrFail();

        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame((string) $operation->uuid, (string) $delivery->origin_operation_uuid);
        Mail::assertSent(NotificationMail::class);

        // A redelivery of the same operation is the same notification.
        app(NotificationOperationApplier::class)->apply($operation);

        $this->assertSame(1, NotificationDelivery::query()
            ->ofType(NotificationType::DepartmentMembershipAdded)
            ->count());
    }

    public function test_the_applier_is_registered_against_the_shared_registry(): void
    {
        $onsite = Node::factory()->create(['node_role' => Node::ROLE_ONSITE]);
        $actor = User::factory()->create();

        $operation = $this->signedOperation(
            originNode: $onsite,
            actor: $actor,
            operationType: NotificationType::ShiftCancelled->operationType(),
            entityType: Shift::class,
            entityId: (string) Str::uuid(),
        );

        $applier = app(NodeOperationApplierRegistry::class)->applierFor($operation);

        $this->assertInstanceOf(NotificationOperationApplier::class, $applier);
    }

    /** NOTIFY-009: suppression is visible on the God Mode readiness surface. */
    public function test_the_readiness_surface_reports_global_and_organization_suppression(): void
    {
        Node::factory()->create([
            'node_role' => Node::ROLE_STANDALONE,
            'is_local' => true,
            'node_name' => 'Test Node',
        ]);

        config()->set('meridian.notifications.suppressed', true);

        Organization::factory()->create([
            'name' => 'Northwood Collective',
            'notifications_suppressed_at' => now(),
        ]);
        Organization::factory()->create(['name' => 'Sending Organization']);

        $keys = array_map(
            fn ($item): string => $item->key,
            app(ConfigurationReadinessCheck::class)->items(),
        );

        $this->assertContains(ConfigurationReadinessCheck::NOTIFICATIONS_SUPPRESSED, $keys);

        $organizationItems = array_values(array_filter(
            app(ConfigurationReadinessCheck::class)->items(),
            fn ($item): bool => str_starts_with(
                $item->key,
                ConfigurationReadinessCheck::ORGANIZATION_NOTIFICATIONS_SUPPRESSED,
            ),
        ));

        $this->assertCount(1, $organizationItems);
        $this->assertStringContainsString('Northwood Collective', $organizationItems[0]->label);
    }

    private function signedOperation(
        Node $originNode,
        User $actor,
        string $operationType,
        string $entityType,
        string $entityId,
    ): SignedNodeOperation {
        $operation = NodeOperation::factory()->create([
            'origin_node_id' => $originNode->id,
            'actor_user_id' => $actor->id,
            'operation_type' => $operationType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_id' => null,
        ]);

        return SignedNodeOperation::fromOperation($operation);
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
}
