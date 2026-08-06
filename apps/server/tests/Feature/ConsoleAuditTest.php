<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\Training;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The God Mode audit trail (M18.34; requirements 2.4; data/API 14.1; UI
 * contract 12.9).
 *
 * The audit table has had no console surface since M4.9 wrote the first row.
 * This screen is the repair view of it, and the cases below are mostly about
 * how it differs from the product surface `organizer.audit`: it spans
 * organizations, it carries node and system rows, it shows the recorded values,
 * and it excludes nothing.
 */
class ConsoleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trail_lists_recorded_changes_with_who_made_them(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $actor = User::factory()->create(['name' => 'Olive Organizer']);

        $this->record($organization, 'department.created', actor: $actor);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.audit'));

        $response->assertOk();
        $response->assertSee('department.created');
        $response->assertSee('Olive Organizer');
        $response->assertSee('Northwood Collective');
    }

    public function test_the_trail_requires_its_god_mode_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->get(route('platform.audit'))->assertForbidden();
    }

    public function test_an_entry_requires_the_same_permission(): void
    {
        $entry = $this->record(Organization::factory()->create(), 'department.created');
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.audit.show', $entry->id))
            ->assertForbidden();
    }

    /**
     * The organization / department / team filter bar the other God Mode list
     * screens carry. `ScopeFiltersLayout` shows only the levels a model
     * declares, so all three appearing is the assertion that all three are
     * declared.
     */
    public function test_the_trail_offers_the_organization_department_and_team_filters(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.audit'));

        $response->assertOk();
        $response->assertSee('scope_organization');
        $response->assertSee('scope_department');
        $response->assertSee('scope_team');
    }

    public function test_the_organization_filter_narrows_the_trail(): void
    {
        $mine = Organization::factory()->create(['name' => 'Northwood Collective']);
        $theirs = Organization::factory()->create(['name' => 'Cascadia Collective']);

        $this->record($mine, 'mine.happened');
        $this->record($theirs, 'theirs.happened');

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.audit', ['scope_organization' => $mine->id]));

        $response->assertOk();
        $response->assertSee('mine.happened');
        $response->assertDontSee('theirs.happened');
    }

    public function test_the_department_filter_narrows_the_trail(): void
    {
        $organization = Organization::factory()->create();
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $gate = Department::factory()->for($organization)->create(['name' => 'Gate']);

        $this->record($organization, 'rangers.happened', department: $rangers);
        $this->record($organization, 'gate.happened', department: $gate);

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.audit', ['scope_department' => $rangers->id]));

        $response->assertOk();
        $response->assertSee('rangers.happened');
        $response->assertDontSee('gate.happened');
    }

    /**
     * A team is a thing changes happen *to* rather than a scope they happen
     * *in*, so the team filter narrows by subject: the team's own row plus the
     * rows of records that belong to it.
     */
    public function test_the_team_filter_narrows_to_the_team_and_what_belongs_to_it(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create(['name' => 'Dirt']);
        $otherTeam = Team::factory()->for($department)->create(['name' => 'Water']);

        $audit = app(AuditService::class);

        $audit->recordForEntity($team, 'team.renamed', organizationId: (string) $organization->id);
        $audit->recordForEntity($otherTeam, 'otherteam.renamed', organizationId: (string) $organization->id);

        $grant = TeamGrant::factory()->create(['team_id' => $team->id]);
        $audit->recordForEntity($grant, 'grant.created', organizationId: (string) $organization->id);

        $shift = Shift::factory()->create(['eligible_team_id' => $team->id]);
        $audit->recordForEntity($shift, 'shift.created', organizationId: (string) $organization->id);

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.audit', ['scope_team' => $team->id]));

        $response->assertOk();
        $response->assertSee('team.renamed');
        $response->assertSee('grant.created');
        $response->assertSee('shift.created');
        $response->assertDontSee('otherteam.renamed');
    }

    /**
     * The map is explicit rather than inferred, so this asserts it still covers
     * every model that reaches a team through a column. A model added to the
     * domain and not added there is absent from the filter rather than wrongly
     * attributed, and this is where that gets noticed.
     */
    public function test_the_team_owned_map_covers_every_model_that_belongs_to_a_team(): void
    {
        $map = AuditEvent::teamOwnedEntities();

        $this->assertSame([
            TeamGrant::class => 'team_id',
            TeamMembership::class => 'team_id',
            \App\Models\TeamDesignation::class => 'team_id',
            Training::class => 'team_id',
            Shift::class => 'eligible_team_id',
        ], $map);

        foreach ($map as $modelClass => $foreignKey) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn((new $modelClass)->getTable(), $foreignKey),
                "{$modelClass} should reach its team through {$foreignKey}.",
            );
        }
    }

    /**
     * ORG-015 is a rule about what *organizing* reaches, not about repair
     * tooling. `organizer.audit` excludes incident and Field Report history; a
     * support operator who cannot see that an incident was reopened cannot
     * answer why a record looks the way it does.
     */
    public function test_the_trail_carries_the_incident_history_the_product_surface_withholds(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();

        $audit = app(AuditService::class);
        $audit->recordForEntity(
            Incident::factory()->for($event)->create(),
            'incident.reopened',
            organizationId: (string) $organization->id,
        );
        $audit->recordForEntity(
            FieldReport::factory()->for($event)->create(),
            'field_report.submitted',
            organizationId: (string) $organization->id,
        );

        $response = $this->actingAs($this->godModeUser())->get(route('platform.audit'));

        $response->assertOk();
        $response->assertSee('incident.reopened');
        $response->assertSee('field_report.submitted');
    }

    /** Node and system rows have no organization and belong to God Mode. */
    public function test_the_trail_carries_rows_that_belong_to_no_organization(): void
    {
        app(AuditService::class)->record(
            action: 'node.paired',
            entityType: 'App\\Models\\Node',
            entityId: (string) Str::uuid(),
        );

        $this->actingAs($this->godModeUser())
            ->get(route('platform.audit'))
            ->assertOk()
            ->assertSee('node.paired');
    }

    /**
     * The recorded values, which is the whole reason this screen exists beside
     * the product one.
     */
    public function test_an_entry_shows_the_values_before_and_after_the_change(): void
    {
        $organization = Organization::factory()->create();

        $entry = app(AuditService::class)->record(
            action: 'staff.status_changed',
            entityType: 'App\\Models\\StaffOrganizationStatus',
            entityId: (string) Str::uuid(),
            organizationId: (string) $organization->id,
            before: ['status' => 'prospective'],
            after: ['status' => 'active'],
            reason: 'Completed their first shift.',
        );

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.audit.show', $entry->id));

        $response->assertOk();
        $response->assertSee('staff.status_changed');
        $response->assertSee('prospective');
        $response->assertSee('active');
        $response->assertSee('Completed their first shift.');
    }

    public function test_an_entry_with_no_user_behind_it_names_a_scheduled_job(): void
    {
        $organization = Organization::factory()->create();
        $entry = $this->record($organization, 'organization.status_evaluated', actor: null);

        $this->actingAs($this->godModeUser())
            ->get(route('platform.audit.show', $entry->id))
            ->assertOk()
            ->assertSee('A scheduled job');
    }

    /** Append-only at the model, so there is no write path to expose. */
    public function test_an_audit_entry_cannot_be_edited_or_removed(): void
    {
        $entry = $this->record(Organization::factory()->create(), 'department.created');

        $this->expectException(\RuntimeException::class);

        $entry->update(['action' => 'tampered']);
    }

    private function record(
        Organization $organization,
        string $action,
        ?User $actor = null,
        ?Department $department = null,
    ): AuditEvent {
        return app(AuditService::class)->record(
            action: $action,
            entityType: 'App\\Models\\Department',
            entityId: (string) ($department?->id
                ?? Department::factory()->for($organization)->create()->id),
            actorUser: $actor,
            organizationId: (string) $organization->id,
            departmentId: $department !== null ? (string) $department->id : null,
        );
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.audit' => true,
            ],
        ]);
    }
}
