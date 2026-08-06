<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditReviewAccess;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit review as a product surface (M18.29; requirements 2.4; UI contract
 * 12.6 `organizer.audit`; ORG-015).
 */
class AuditReviewHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_reads_their_own_organizations_audit_record(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $other = Organization::factory()->create();

        $this->record($organization, 'department.created');
        $this->record($other, 'department.created');

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/audit");

        $response->assertOk();
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonPath('entries.0.action', 'department.created');
        $response->assertJsonPath('entries.0.actor_name', $organizer->name);
    }

    public function test_a_caller_without_the_capability_is_refused(): void
    {
        [$organization] = $this->organizerScaffold();

        $this->actingAsClient(User::factory()->create())
            ->getJson("/api/organizations/{$organization->id}/audit")
            ->assertForbidden();
    }

    /** TEAM-014: the Staff Coordinator carries no other governance capability. */
    public function test_a_staff_coordinator_does_not_reach_audit_review(): void
    {
        [$organization] = $this->organizerScaffold();
        $coordinator = $this->userHoldingRoleIn(
            $organization,
            PermissionCatalog::ROLE_STAFF_COORDINATOR,
        );

        $this->actingAsClient($coordinator)
            ->getJson("/api/organizations/{$organization->id}/audit")
            ->assertForbidden();
    }

    /**
     * ORG-015: organizing reaches no incident and no Field Report, and an audit
     * row naming one is a way of reading it.
     */
    public function test_incident_and_field_report_history_is_absent_from_an_organizers_audit_review(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $event = Event::factory()->for($organization)->create();

        $incident = Incident::factory()->for($event)->create();
        $fieldReport = FieldReport::factory()->for($event)->create();

        app(AuditService::class)->recordForEntity(
            entity: $incident,
            action: 'incident.reopened',
            organizationId: (string) $organization->id,
            eventId: (string) $event->id,
        );
        app(AuditService::class)->recordForEntity(
            entity: $fieldReport,
            action: 'field_report.submitted',
            organizationId: (string) $organization->id,
            eventId: (string) $event->id,
        );
        $this->record($organization, 'staff.status_changed');

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/audit");

        $response->assertOk();

        $actions = collect($response->json('entries'))->pluck('action')->all();

        $this->assertSame(['staff.status_changed'], $actions);
        $this->assertNotContains('incident.reopened', $response->json('options.actions'));
        $this->assertNotContains('field_report.submitted', $response->json('options.actions'));
    }

    /**
     * The excluded set names every incident and Field Report model that carries
     * an audit row, so a new IMS record cannot be added and quietly become
     * organizer-readable through history.
     */
    public function test_the_excluded_entity_types_cover_the_whole_incident_and_field_report_domain(): void
    {
        $excluded = AuditReviewAccess::excludedEntityTypes();

        foreach ([
            \App\Models\Incident::class,
            \App\Models\IncidentTimelineEntry::class,
            \App\Models\IncidentFieldReport::class,
            \App\Models\IncidentLink::class,
            \App\Models\IncidentStaff::class,
            \App\Models\IncidentListPreset::class,
            \App\Models\FieldReport::class,
            \App\Models\FieldReportAppend::class,
        ] as $model) {
            $this->assertContains((new $model)->getMorphClass(), $excluded);
        }

        // The configurable incident type list is organization configuration an
        // organizer maintains (M18.14A; ORG-018), so its history stays visible.
        $this->assertNotContains(
            (new \App\Models\IncidentType)->getMorphClass(),
            $excluded,
        );
    }

    /** Node and system history has no organization and belongs to God Mode. */
    public function test_an_entry_carrying_no_organization_is_outside_organizer_review(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();

        app(AuditService::class)->record(
            action: 'node.paired',
            entityType: 'App\\Models\\Node',
            entityId: 'a-node',
        );

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/audit")
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    /**
     * Requirements 2.4 asks for attribution. The values themselves stay in God
     * Mode (M18.34), because an audit payload is whatever the writing path
     * snapshotted and this surface is not an unscoped read of it.
     */
    public function test_a_row_names_the_fields_that_changed_and_carries_no_values(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();

        app(AuditService::class)->record(
            action: 'staff.profile_updated',
            entityType: 'App\\Models\\Staff',
            entityId: 'a-staff-record',
            organizationId: (string) $organization->id,
            before: ['phone' => '555-0100', 'preferred_name' => 'Robin'],
            after: ['phone' => '555-0199', 'preferred_name' => 'Robin'],
            reason: 'Corrected at the desk.',
        );

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/audit");

        $response->assertOk();
        $response->assertJsonPath('entries.0.changed_fields', ['phone', 'preferred_name']);
        $response->assertJsonPath('entries.0.reason', 'Corrected at the desk.');

        $body = $response->getContent();

        $this->assertStringNotContainsString('555-0100', (string) $body);
        $this->assertStringNotContainsString('555-0199', (string) $body);
    }

    public function test_the_record_filters_by_action_and_by_when_it_happened(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();

        $this->record($organization, 'department.created')
            ->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        $this->record($organization, 'team.created');

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/audit?action=team.created")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('entries.0.action', 'team.created');

        $this->actingAsClient($organizer)
            ->getJson(sprintf(
                '/api/organizations/%s/audit?from=%s',
                $organization->id,
                urlencode(now()->subDay()->toIso8601String()),
            ))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('entries.0.action', 'team.created');
    }

    private function record(Organization $organization, string $action): \App\Models\AuditEvent
    {
        return app(AuditService::class)->record(
            action: $action,
            entityType: 'App\\Models\\Department',
            entityId: (string) Department::factory()->for($organization)->create()->id,
            actorUser: $this->organizerFor($organization),
            organizationId: (string) $organization->id,
        );
    }

    /** @var array<string, User> */
    private array $organizers = [];

    private function organizerFor(Organization $organization): User
    {
        return $this->organizers[(string) $organization->id]
            ??= $this->userHoldingRoleIn($organization, PermissionCatalog::ROLE_ORGANIZER);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organizerScaffold(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        return [$organization->refresh(), $this->organizerFor($organization)];
    }

    private function userHoldingRoleIn(Organization $organization, string $roleCode): User
    {
        $department = Department::query()
            ->where('organization_id', $organization->id)
            ->firstOr(fn (): Department => Department::factory()->for($organization)->create());

        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($department)->create(['name' => $roleCode.' team']);
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
                ->firstOrFail()->id,
        ]);

        return $user;
    }
}
