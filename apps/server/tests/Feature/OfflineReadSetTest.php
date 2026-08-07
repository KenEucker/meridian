<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\FieldReport;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Modules\ActiveModuleResolver;
use Database\Seeders\AttendanceScenarioSeeder;
use Database\Seeders\DevelopmentScenarioSeeder;
use Database\Seeders\DocumentScenarioSeeder;
use Database\Seeders\EquipmentScenarioSeeder;
use Database\Seeders\IncidentScenarioSeeder;
use Database\Seeders\IncidentTypeDefaultsSeeder;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\ShiftScenarioSeeder;
use Database\Seeders\TrainingAndWaiverScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The regular-staff offline read set (M18.46; CLIENT-021, CLIENT-022, MOD-016;
 * technical spec 9.3, 9.5, 11A.7; data/API 7.1, 7.3; ADR-0003).
 *
 * These are the composition and scope assertions `Tests\Support\PowerSyncRules`
 * and `PowerSyncPermissionScopedReplicationTest` made against the sync rules,
 * ported onto the endpoint that replaces them. The rules YAML is retired by
 * M18.51; what it proved is not, and it is proved here the same way — by
 * executing the thing that decides what a device receives and asserting which
 * records come back, rather than by reading a definition as text.
 *
 * The properties under test are the requirements' own: a device holds nothing
 * its user could not retrieve through the API (CLIENT-021), a change to what
 * the user holds changes the next set (CLIENT-022), and a module the
 * organization does not run contributes nothing whatever the user may read
 * (MOD-016).
 */
class OfflineReadSetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $rangers;

    private Team $dirt;

    private Event $event;

    private Shift $shift;

    /** A designated lead of the Dirt team. */
    private Staff $sam;

    private User $samUser;

    /** An ordinary member of the Dirt team, assigned to the shift. */
    private Staff $vera;

    private User $veraUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $this->rangers = Department::factory()->for($this->organization)->create(['name' => 'Rangers']);
        $this->dirt = Team::factory()->for($this->rangers)->create(['name' => 'Dirt']);
        $this->event = Event::factory()->for($this->organization)->create(['name' => 'Emberfall 2026']);

        EventDepartmentAssignment::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
        ]);

        $this->shift = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
        ]);

        [$this->sam, $this->samUser] = $this->staffMember('Sam Shiftlead', $this->dirt, 'lead');
        [$this->vera, $this->veraUser] = $this->staffMember('Vera Staff', $this->dirt, 'member');

        ShiftAssignment::factory()->create([
            'shift_id' => $this->shift->id,
            'staff_id' => $this->vera->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Composition (technical spec 9.3)
    |--------------------------------------------------------------------------
    */

    public function test_a_staff_member_receives_their_own_associations(): void
    {
        $sections = $this->sectionsFor($this->veraUser);

        $this->assertSame([$this->organization->id], $this->ids($sections, 'organizations'));
        $this->assertSame([$this->rangers->id], $this->ids($sections, 'departments'));
        $this->assertSame([$this->dirt->id], $this->ids($sections, 'teams'));
        $this->assertSame([$this->event->id], $this->ids($sections, 'events'));
        $this->assertSame([$this->shift->id], $this->ids($sections, 'shifts'));
        $this->assertCount(1, $sections['shift_assignments']);
        $this->assertCount(1, $sections['department_memberships']);
        $this->assertCount(1, $sections['team_memberships']);
        $this->assertSame([$this->vera->id], $this->ids($sections, 'staff'));
    }

    /**
     * "Field report form" is one of the section 9.3 lines, and it is the one a
     * device needs before it has anything to store: the form is what an offline
     * report is composed in.
     */
    public function test_the_set_carries_the_field_report_form_and_its_photo_limits(): void
    {
        $sections = $this->sectionsFor($this->veraUser);

        $this->assertCount(1, $sections['field_report_form']);

        $form = $sections['field_report_form'][0];

        $this->assertSame('submit-field-report', $form['command']);
        $this->assertTrue($form['offline_writable']);
        $this->assertSame(2, $form['photos']['max_count']);
        $this->assertContains('title', array_column($form['fields'], 'name'));
        $this->assertContains('body', array_column($form['fields'], 'name'));
    }

    public function test_the_set_carries_the_callers_own_field_reports_and_no_one_elses(): void
    {
        $mine = $this->fieldReport($this->vera, $this->veraUser);
        $theirs = $this->fieldReport($this->sam, $this->samUser);

        $this->assertSame([$mine->id], $this->ids($this->sectionsFor($this->veraUser), 'field_reports'));
        $this->assertSame([$theirs->id], $this->ids($this->sectionsFor($this->samUser), 'field_reports'));
    }

    /**
     * Notes and Briefing inclusions are section 9.3 lines this build cannot
     * compose, and the response says so rather than omitting the key. A client
     * cannot otherwise tell "you authored no notes" from "this Meridian has no
     * Notes", and those are different things to tell a person with no signal.
     */
    public function test_sections_this_build_cannot_compose_are_named_rather_than_omitted(): void
    {
        $readiness = $this->readSet($this->veraUser)->json('readiness');

        $this->assertSame(
            ['notes', 'briefing_note_presentations'],
            array_column($readiness['deferred_sections'], 'section'),
        );

        foreach ($readiness['deferred_sections'] as $deferred) {
            $this->assertSame(ModuleKey::Briefing->value, $deferred['module']);
            $this->assertNotSame('', $deferred['reason']);
            $this->assertStringContainsString('Milestone 15', $deferred['owning_work']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Scope (CLIENT-021; technical spec 11A.7)
    |--------------------------------------------------------------------------
    */

    public function test_a_staff_member_receives_nothing_belonging_to_another_organization(): void
    {
        $otherTeam = Team::factory()->create();
        [$stranger, $strangerUser] = $this->staffMember('Ira Ineligible', $otherTeam, 'member');

        $strangerShift = Shift::factory()->create([
            'event_id' => Event::factory()->create([
                'organization_id' => $otherTeam->department->organization_id,
            ])->id,
            'department_id' => $otherTeam->department_id,
            'eligible_team_id' => $otherTeam->id,
        ]);
        ShiftAssignment::factory()->create([
            'shift_id' => $strangerShift->id,
            'staff_id' => $stranger->id,
        ]);

        $vera = $this->sectionsFor($this->veraUser);
        $theirs = $this->sectionsFor($strangerUser);

        $this->assertContains($this->shift->id, $this->ids($vera, 'shifts'));
        $this->assertNotContains($strangerShift->id, $this->ids($vera, 'shifts'));
        $this->assertContains($strangerShift->id, $this->ids($theirs, 'shifts'));
        $this->assertNotContains($this->shift->id, $this->ids($theirs, 'shifts'));
        $this->assertNotContains($this->dirt->id, $this->ids($theirs, 'teams'));
        $this->assertNotContains($this->vera->id, $this->ids($theirs, 'staff'));
    }

    public function test_a_login_with_no_staff_profile_receives_nothing(): void
    {
        $response = $this->readSet(User::factory()->create());

        $response->assertOk();

        $this->assertSame([], $response->json('sections'));
        $this->assertSame([], $response->json('readiness.counts'));
        $this->assertSame([], $response->json('readiness.effective_role_codes'));
    }

    public function test_an_unpublished_document_reaches_nobody(): void
    {
        $published = PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $draft = PolicyDocument::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $documents = $this->ids($this->sectionsFor($this->veraUser), 'policy_documents');

        $this->assertContains($published->id, $documents);
        $this->assertNotContains($draft->id, $documents);
    }

    /**
     * A fragment travels because a document the caller may read references it,
     * and not on its own scope. Otherwise the fragment library would be a
     * second, wider way into governance text than the documents it appears in.
     */
    public function test_a_fragment_reaches_the_device_only_through_a_visible_document(): void
    {
        $published = PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $referenced = DocumentFragment::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $unreferenced = DocumentFragment::factory()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        DocumentFragmentReference::query()->create([
            'document_type' => DocumentFragmentReference::DOCUMENT_TYPE_POLICY,
            'document_id' => $published->id,
            'fragment_id' => $referenced->id,
            'token' => '{{fragment:'.$referenced->slug.'}}',
            'fragment_version_at_last_edit' => $referenced->version,
        ]);

        $fragments = $this->ids($this->sectionsFor($this->veraUser), 'document_fragments');

        $this->assertContains($referenced->id, $fragments);
        $this->assertNotContains($unreferenced->id, $fragments);
    }

    /**
     * The M8.2 exclusions, which are about what a device may hold rather than
     * about whose record it is: they apply to the caller's own staff row too.
     * `staff.me` serves these fields online under its own rules (M18.20).
     */
    public function test_the_set_carries_no_staff_field_the_replication_boundary_excludes(): void
    {
        $this->vera->forceFill([
            'email' => 'vera@northwood.test',
            'phone' => '555-0100',
            'date_of_birth' => '1990-01-01',
            'emergency_contact_name' => 'Someone Else',
            'emergency_contact_phone' => '555-0199',
            'profile_picture_path' => 'staff/vera.webp',
        ])->save();

        StaffOrganizationStatus::query()
            ->where('staff_id', $this->vera->id)
            ->update(['status_reason' => 'A note the organization keeps.']);

        $body = $this->readSet($this->veraUser)->getContent();

        foreach ([
            'vera@northwood.test',
            '555-0100',
            '1990-01-01',
            'Someone Else',
            '555-0199',
            'staff/vera.webp',
            'A note the organization keeps.',
            'emergency_contact',
            'date_of_birth',
            'status_reason',
            'profile_picture',
        ] as $excluded) {
            $this->assertStringNotContainsString($excluded, (string) $body);
        }
    }

    public function test_incidents_are_not_carried(): void
    {
        $sections = $this->sectionsFor($this->veraUser);

        $this->assertArrayNotHasKey('incidents', $sections);
        $this->assertArrayNotHasKey('incident_timeline_entries', $sections);
    }

    /*
    |--------------------------------------------------------------------------
    | The set follows the caller's current standing (CLIENT-022)
    |--------------------------------------------------------------------------
    */

    /**
     * Nothing is carried in the token, so a withdrawn grant changes the next
     * set rather than the next sign-in. The regular-staff sections are
     * membership-scoped and survive a demotion — a demoted lead is still staff
     * — and the boundary the set reports moves with the grant.
     */
    public function test_a_revoked_grant_changes_the_next_set(): void
    {
        $grant = $this->grant(PermissionCatalog::ROLE_SHIFT_LEAD, $this->dirt);

        $before = $this->readSet($this->samUser);
        $this->assertSame(
            [PermissionCatalog::ROLE_SHIFT_LEAD],
            $before->json('readiness.effective_role_codes'),
        );

        $grant->forceFill(['revoked_at' => now()])->save();

        $after = $this->readSet($this->samUser);
        $this->assertSame([], $after->json('readiness.effective_role_codes'));
        $this->assertNotSame($before->json('version'), $after->json('version'));

        // The demotion withdraws the role, not the person's own records.
        $this->assertSame([$this->dirt->id], $this->ids($this->sectionsFor($this->samUser), 'teams'));
    }

    public function test_archiving_a_team_membership_removes_the_team_from_the_set(): void
    {
        $this->assertSame([$this->dirt->id], $this->ids($this->sectionsFor($this->veraUser), 'teams'));

        TeamMembership::query()
            ->where('team_id', $this->dirt->id)
            ->where('staff_id', $this->vera->id)
            ->update(['archived_at' => now()]);

        $this->assertSame([], $this->ids($this->sectionsFor($this->veraUser), 'teams'));
    }

    /*
    |--------------------------------------------------------------------------
    | Module scoping (MOD-016)
    |--------------------------------------------------------------------------
    */

    /**
     * An inactive module contributes no section at all — the key is absent
     * rather than present and empty, because MOD-012 makes an inactive module
     * absent from the product rather than visibly switched off. Core sections
     * are untouched: an organization with every module inactive is still an
     * organization with departments, teams, events, and staff (MOD-004).
     */
    public function test_an_inactive_modules_records_are_absent(): void
    {
        $this->withoutModules(ModuleKey::Scheduling, ModuleKey::Documents);

        PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $sections = $this->sectionsFor($this->veraUser);

        foreach ([
            'shifts',
            'shift_assignments',
            'policy_documents',
            'procedure_documents',
            'document_fragments',
            'document_fragment_references',
            'document_acknowledgments',
            'document_acknowledgment_requirements',
        ] as $absent) {
            $this->assertArrayNotHasKey($absent, $sections, "The {$absent} section survived its module being inactive.");
        }

        foreach (['organizations', 'departments', 'teams', 'events', 'staff'] as $core) {
            $this->assertArrayHasKey($core, $sections);
        }

        // Incident Management is still active, so its sections stay.
        $this->assertArrayHasKey('field_report_form', $sections);
    }

    /**
     * Module state belongs to an organization, and a staff member can hold
     * standing in more than one. A module one organization has switched off
     * must not take another organization's records away.
     */
    public function test_a_module_inactive_in_one_organization_leaves_anothers_records_alone(): void
    {
        $otherOrganization = Organization::factory()->create(['name' => 'Southreach Assembly']);
        $otherDepartment = Department::factory()->for($otherOrganization)->create();
        $otherTeam = Team::factory()->for($otherDepartment)->create();
        $otherEvent = Event::factory()->for($otherOrganization)->create();

        EventDepartmentAssignment::factory()->create([
            'event_id' => $otherEvent->id,
            'department_id' => $otherDepartment->id,
        ]);

        $this->alsoBelongsTo($this->vera, $otherTeam);

        $mine = PolicyDocument::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
        ]);

        $theirs = PolicyDocument::factory()->published()->create([
            'organization_id' => $otherOrganization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $otherOrganization->id,
        ]);

        $this->withoutModulesIn((string) $otherOrganization->id, ModuleKey::Documents);

        $documents = $this->ids($this->sectionsFor($this->veraUser), 'policy_documents');

        $this->assertContains($mine->id, $documents);
        $this->assertNotContains($theirs->id, $documents);
    }

    /*
    |--------------------------------------------------------------------------
    | Versioning (the 304 the weak connection depends on)
    |--------------------------------------------------------------------------
    */

    public function test_an_unchanged_set_answers_304_with_no_payload(): void
    {
        $first = $this->readSet($this->veraUser);
        $first->assertOk();

        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertSame('"'.$first->json('version').'"', $etag);

        $second = $this->readSet($this->veraUser, headers: ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
        $this->assertSame($etag, $second->headers->get('ETag'));
    }

    /**
     * The version is over the content and not over the clock: two composals of
     * an unchanged set agree, or every refresh would be a transfer.
     */
    public function test_the_version_is_stable_across_composals_of_an_unchanged_set(): void
    {
        $this->assertSame(
            $this->readSet($this->veraUser)->json('version'),
            $this->readSet($this->veraUser)->json('version'),
        );
    }

    public function test_a_changed_set_answers_with_a_payload_and_a_new_version(): void
    {
        $etag = $this->readSet($this->veraUser)->headers->get('ETag');

        $added = Shift::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'eligible_team_id' => $this->dirt->id,
        ]);
        ShiftAssignment::factory()->create([
            'shift_id' => $added->id,
            'staff_id' => $this->vera->id,
        ]);

        $response = $this->readSet($this->veraUser, headers: ['If-None-Match' => (string) $etag]);

        $response->assertOk();
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $this->assertContains($added->id, $this->ids($response->json('sections'), 'shifts'));
    }

    /*
    |--------------------------------------------------------------------------
    | The read is a read
    |--------------------------------------------------------------------------
    */

    public function test_the_read_writes_no_record_and_no_audit_event(): void
    {
        $audits = AuditEvent::query()->count();

        $this->readSet($this->veraUser)->assertOk();

        $this->assertSame($audits, AuditEvent::query()->count());
        $this->assertSame(0, FieldReport::query()->count());
    }

    public function test_the_endpoint_requires_a_credential(): void
    {
        $this->getJson(route('api.offline-read-set'))->assertUnauthorized();
    }

    public function test_an_event_the_caller_holds_no_association_with_is_refused(): void
    {
        $other = Event::factory()->create();

        $this->actingAsClient($this->veraUser)
            ->getJson(route('api.offline-read-set', ['event_id' => $other->id]))
            ->assertNotFound()
            ->assertJsonPath('reason_code', 'event_context_unavailable');
    }

    /**
     * The context event is what bounds staleness under technical spec 11A.4:
     * a cached set is usable for the duration of the event the node is locked
     * to, and a client holding no event context has nothing to bound it by and
     * must refresh before the set is trusted.
     */
    public function test_the_set_reports_the_window_its_staleness_is_bounded_by(): void
    {
        $this->event->forceFill([
            'active_event_window_ends_at' => now()->addDays(3),
        ])->save();

        $readiness = $this->readSet($this->veraUser)->json('readiness');

        $this->assertSame($this->event->id, $readiness['context_event_id']);
        $this->assertNotNull($readiness['usable_until']);
    }

    /*
    |--------------------------------------------------------------------------
    | The measurement M18.48 chooses a storage layer from
    |--------------------------------------------------------------------------
    */

    /**
     * The recorded size of the regular-staff set against the seeded event
     * scenario.
     *
     * Whether the section 9.3 set can refresh whole or needs deltas is the
     * input M18.48 picks a client store on, and ADR-0003 makes it a measurement
     * rather than a judgement — which means it has to be measured somewhere
     * that stays true as the set grows. The number is written to the test
     * output on every run, and the ceiling is a guard rather than a target: it
     * fails when the regular-staff set has changed size by an order of
     * magnitude, which is the moment the storage decision would need revisiting.
     *
     * Measured at M18.46: **10,964 bytes (10.7 kB)** for `vera.staff`, the
     * seeded ordinary staff member — 1 organization, 1 department, 1 team, 2
     * events, 3 shifts, 4 documents, 1 fragment, 1 Field Report. The set is
     * blob-shaped and small enough to refresh whole on a weak connection, and
     * most of it is document markdown rather than row count. The Logistics
     * indexes M18.47 measures are the ones that decide whether a query engine
     * is needed; this half does not.
     */
    public function test_the_regular_staff_set_size_is_measured_against_the_seeded_scenario(): void
    {
        $this->seed([
            PermissionCatalogSeeder::class,
            DevelopmentScenarioSeeder::class,
            IncidentTypeDefaultsSeeder::class,
            TrainingAndWaiverScenarioSeeder::class,
            ShiftScenarioSeeder::class,
            EquipmentScenarioSeeder::class,
            AttendanceScenarioSeeder::class,
            DocumentScenarioSeeder::class,
            IncidentScenarioSeeder::class,
        ]);

        $seeded = User::query()->where('email', 'vera.staff@northwood-collective.test')->firstOrFail();

        $response = $this->readSet($seeded);
        $response->assertOk();

        $bytes = strlen((string) $response->getContent());
        $counts = $response->json('readiness.counts');

        fwrite(STDERR, sprintf(
            "\n[M18.46] regular-staff offline read set: %d bytes (%.1f kB) for %s; rows: %s\n",
            $bytes,
            $bytes / 1024,
            $seeded->email,
            json_encode($counts),
        ));

        $this->assertGreaterThan(0, $bytes);
        $this->assertLessThan(
            2 * 1024 * 1024,
            $bytes,
            "The regular-staff read set measured {$bytes} bytes against the seeded scenario. "
            .'A set this size refreshes whole only on a connection an event does not have; '
            .'revisit the M18.48 storage decision before raising this ceiling.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, string>  $headers
     */
    private function readSet(User $user, array $headers = []): TestResponse
    {
        return $this->actingAsClient($user)
            ->getJson(route('api.offline-read-set'), $headers);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function sectionsFor(User $user): array
    {
        return $this->readSet($user)->json('sections');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @return list<string>
     */
    private function ids(array $sections, string $section): array
    {
        return array_column($sections[$section] ?? [], 'id');
    }

    /**
     * Switch modules off for every organization, or for one named organization.
     *
     * `organization_modules` does not exist until M19.11, so the state is
     * supplied through the resolver that will read it. The boundary under test
     * is the composer's, and it is the same boundary whichever side of that
     * table the state comes from.
     */
    private function withoutModules(ModuleKey ...$inactive): void
    {
        $this->bindModules($inactive, null);
    }

    private function withoutModulesIn(string $organizationId, ModuleKey ...$inactive): void
    {
        $this->bindModules($inactive, $organizationId);
    }

    /**
     * @param  list<ModuleKey>  $inactive
     */
    private function bindModules(array $inactive, ?string $only): void
    {
        $this->app->instance(ActiveModuleResolver::class, new class($inactive, $only) extends ActiveModuleResolver
        {
            /**
             * @param  list<ModuleKey>  $inactive
             */
            public function __construct(
                private readonly array $inactive,
                private readonly ?string $only,
            ) {}

            public function activeFor(string $organizationId): array
            {
                if ($this->only !== null && $this->only !== $organizationId) {
                    return ModuleKey::cases();
                }

                return array_values(array_filter(
                    ModuleKey::cases(),
                    fn (ModuleKey $module): bool => ! in_array($module, $this->inactive, true),
                ));
            }
        });
    }

    private function grant(string $roleCode, Team $team, ?Event $event = null): TeamGrant
    {
        return TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => $event?->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);
    }

    private function fieldReport(Staff $staff, User $user): FieldReport
    {
        return FieldReport::factory()->create([
            'event_id' => $this->event->id,
            'department_id' => $this->rangers->id,
            'team_id' => $this->dirt->id,
            'staff_id' => $staff->id,
            'submitted_by_user_id' => $user->id,
        ]);
    }

    /**
     * @return array{0: Staff, 1: User}
     */
    private function staffMember(string $name, Team $team, string $membershipRole): array
    {
        $staff = Staff::factory()->create(['legal_name' => $name]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $team->department->organization_id,
            'staff_id' => $staff->id,
        ]);

        $this->alsoBelongsTo($staff, $team, $membershipRole);

        return [$staff, $user];
    }

    private function alsoBelongsTo(Staff $staff, Team $team, string $membershipRole = 'member'): void
    {
        $team->loadMissing('department');

        StaffOrganizationStatus::query()->firstOrCreate([
            'organization_id' => $team->department->organization_id,
            'staff_id' => $staff->id,
        ], [
            'status' => 'active',
        ]);

        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => $membershipRole,
        ]);
    }
}
