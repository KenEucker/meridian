<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentFragment;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The document acknowledgment path's endpoints (M18.6; POL-023 through
 * POL-027, POL-043 through POL-047).
 *
 * `DocumentAcknowledgmentServiceTest` and `DocumentAcknowledgmentRequirementTest`
 * cover the domain rules against the services directly. These are about the path
 * to them: who is asked, who may ask, what the record says afterwards, and — the
 * two requirements with no positive behavior to assert — what an outstanding
 * acknowledgment still does not stop.
 */
class DocumentAcknowledgmentHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_member_reads_what_they_have_been_asked_with_the_document_text_inline(): void
    {
        $scenario = $this->organizationScenario();

        DocumentFragment::factory()->for($scenario['organization'])->create([
            'slug' => 'radio-expectations',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'markdown_source' => 'Use the radio only when needed.',
        ]);

        $document = $this->publishedPolicy($scenario['organization'], [
            'title' => 'Radio safety',
            'document_revision' => 3,
            'fragment_revision' => 2,
            'markdown_source' => "# Radio safety\n\n{{fragment:radio-expectations}}",
        ]);

        $requirement = $this->requirementFor($document, $scenario['organization']);

        $response = $this->actingAsClient($scenario['user'])
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk()
            ->assertJsonCount(1, 'requirements')
            ->assertJsonPath('requirements.0.requirement_id', (string) $requirement->id)
            ->assertJsonPath('requirements.0.document_title', 'Radio safety')
            ->assertJsonPath('requirements.0.document_version', '3.02')
            ->assertJsonPath('requirements.0.requirement_context_label', 'Staff signup')
            ->assertJsonPath('requirements.0.acknowledged', false)
            ->assertJsonPath('requirements.0.acknowledged_version', null)
            ->assertJsonPath('outstanding_count', 1);

        // POL-022: the fragment is document text by the time it reaches the
        // person being asked to accept it, not a token they have to resolve.
        $rendered = (string) $response->json('requirements.0.rendered_html');
        $this->assertStringContainsString('Use the radio only when needed.', $rendered);
        $this->assertStringNotContainsString('{{fragment:radio-expectations}}', $rendered);
    }

    public function test_an_acknowledgment_records_the_version_that_was_shown(): void
    {
        Node::factory()->create();

        $scenario = $this->organizationScenario();
        $document = $this->publishedPolicy($scenario['organization'], [
            'document_revision' => 4,
            'fragment_revision' => 1,
        ]);
        $requirement = $this->requirementFor($document, $scenario['organization']);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/acknowledge-document', [
                'requirement_id' => (string) $requirement->id,
            ])
            ->assertOk()
            ->assertJsonPath('requirement.acknowledged', true)
            // POL-043: the acknowledged document and version, read back.
            ->assertJsonPath('requirement.acknowledged_version', '4.01')
            ->assertJsonPath('requirement.document_changed_since', false);

        $acknowledgment = DocumentAcknowledgment::query()->sole();

        $this->assertSame((string) $scenario['user']->id, (string) $acknowledgment->user_id);
        $this->assertSame((string) $scenario['staff']->id, (string) $acknowledgment->staff_id);
        $this->assertSame((string) $document->id, (string) $acknowledgment->document_id);
        $this->assertSame(4, $acknowledgment->document_revision);
        $this->assertSame(1, $acknowledgment->fragment_revision);
        $this->assertNotNull($acknowledgment->accepted_by_node_id);
    }

    public function test_a_document_that_changed_after_acceptance_is_reported_and_not_re_required(): void
    {
        Node::factory()->create();

        $scenario = $this->organizationScenario();
        $document = $this->publishedPolicy($scenario['organization'], [
            'document_revision' => 2,
            'fragment_revision' => 0,
        ]);
        $requirement = $this->requirementFor($document, $scenario['organization']);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/acknowledge-document', [
                'requirement_id' => (string) $requirement->id,
            ])
            ->assertOk();

        $document->forceFill(['document_revision' => 3])->save();

        // POL-045: the version moved and nothing is outstanding because of it.
        // The move is reported so a reader is not left wondering, and it is not
        // a task.
        $this->actingAsClient($scenario['user'])
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk()
            ->assertJsonPath('outstanding_count', 0)
            ->assertJsonPath('requirements.0.acknowledged', true)
            ->assertJsonPath('requirements.0.acknowledged_version', '2.00')
            ->assertJsonPath('requirements.0.document_version', '3.00')
            ->assertJsonPath('requirements.0.document_changed_since', true);
    }

    public function test_an_outstanding_acknowledgment_is_not_a_shift_signup_or_credential_gate(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $scenario = $this->organizationScenario();
        $document = $this->publishedPolicy($scenario['organization']);
        $this->requirementFor($document, $scenario['organization']);

        $event = Event::factory()->for($scenario['organization'])->create();
        $scenario['department']->loadMissing('defaultTeam');
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $scenario['department']->id,
            'eligible_team_id' => $scenario['department']->defaultTeam->id,
            'starts_at' => Carbon::parse('2026-06-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-06-10 17:00:00'),
        ]);

        // The requirement is outstanding for this staff member throughout.
        $this->actingAsClient($scenario['user'])
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk()
            ->assertJsonPath('outstanding_count', 1)
            // POL-026 and POL-027 in the answer itself, so a surface listing
            // outstanding items beside a schedule is not left to imply
            // otherwise.
            ->assertJsonPath('gating.blocks_shift_signup', false)
            ->assertJsonPath('gating.blocks_credential_eligibility', false);

        // POL-026: signup is accepted with the acknowledgment outstanding.
        app(ShiftSignupService::class)->signUp($shift, $scenario['staff'], $scenario['user']);

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $shift->id,
            'staff_id' => $scenario['staff']->id,
        ]);

        // POL-027: and the credential the signup earns is eligible, with the
        // acknowledgment still outstanding.
        $evaluation = app(CredentialEligibilityService::class)->evaluate($event, $scenario['staff']);

        $this->assertTrue($evaluation->eligible, 'An outstanding acknowledgment must not block credential eligibility.');
        $this->assertNull($evaluation->blockReason);
    }

    public function test_a_requirement_addressed_to_somebody_else_is_refused_and_absent(): void
    {
        Node::factory()->create();

        $scenario = $this->organizationScenario();
        $otherOrganization = Organization::factory()->create();
        $document = $this->publishedPolicy($otherOrganization);
        $requirement = $this->requirementFor($document, $otherOrganization);

        // Absent from the read, because it was never asked of them.
        $this->actingAsClient($scenario['user'])
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk()
            ->assertJsonCount(0, 'requirements');

        // And refused by the command, which is the half that matters: a read
        // that hides a row is not authorization.
        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/acknowledge-document', [
                'requirement_id' => (string) $requirement->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This acknowledgment was not asked of you.');

        $this->assertDatabaseCount('document_acknowledgments', 0);
    }

    public function test_a_requirement_pointing_at_an_unpublished_document_is_absent(): void
    {
        $scenario = $this->organizationScenario();
        $draft = PolicyDocument::factory()->for($scenario['organization'])->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
        ]);
        $this->requirementFor($draft, $scenario['organization']);

        $this->actingAsClient($scenario['user'])
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk()
            ->assertJsonCount(0, 'requirements')
            ->assertJsonPath('outstanding_count', 0);
    }

    public function test_a_retired_requirement_stops_being_asked_and_keeps_its_acknowledgments(): void
    {
        Node::factory()->create();

        $scenario = $this->organizationScenario();
        $organizer = $this->organizerFor($scenario['organization']);
        $document = $this->publishedPolicy($scenario['organization']);
        $requirement = $this->requirementFor($document, $scenario['organization']);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/acknowledge-document', ['requirement_id' => (string) $requirement->id])
            ->assertOk();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/set-document-acknowledgment-requirement-active', [
                'requirement_id' => (string) $requirement->id,
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('requirement.active', false)
            // Retiring is a decision to stop asking, not a claim nobody
            // answered.
            ->assertJsonPath('requirement.acknowledged_count', 1);

        $this->assertDatabaseCount('document_acknowledgments', 1);

        $this->actingAsClient($scenario['user'])
            ->getJson('/api/document-acknowledgments/me')
            ->assertOk()
            ->assertJsonCount(0, 'requirements');

        $this->assertDatabaseHas('audit_events', [
            'entity_id' => $requirement->id,
            'action' => 'document_acknowledgment_requirement.retired',
        ]);
    }

    public function test_an_organizer_creates_a_requirement_and_reads_who_has_answered_it(): void
    {
        Node::factory()->create();

        $scenario = $this->organizationScenario();
        $organizer = $this->organizerFor($scenario['organization']);
        $document = $this->publishedPolicy($scenario['organization'], ['title' => 'Radio safety']);

        $created = $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-document-acknowledgment-requirement', [
                'organization_id' => (string) $scenario['organization']->id,
                'document_type' => 'policy',
                'document_id' => (string) $document->id,
                'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT,
                'scope_id' => (string) $scenario['department']->id,
                'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_TRAINING,
            ])
            ->assertCreated()
            ->assertJsonPath('requirement.document_title', 'Radio safety')
            ->assertJsonPath('requirement.requirement_context_label', 'Training')
            ->assertJsonPath('requirement.active', true);

        $requirementId = (string) $created->json('requirement.id');

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/acknowledge-document', ['requirement_id' => $requirementId])
            ->assertOk();

        $review = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$scenario['organization']->id}/document-acknowledgments")
            ->assertOk()
            ->assertJsonCount(1, 'requirements')
            ->assertJsonPath('requirements.0.id', $requirementId)
            ->assertJsonPath('requirements.0.acknowledged_count', 1)
            ->assertJsonPath('requirements.0.staff.0.staff_id', (string) $scenario['staff']->id)
            ->assertJsonPath('requirements.0.staff.0.acknowledged', true);

        // Identity enough to know who answered, and no further — the same line
        // the credential administration list draws.
        $review->assertJsonMissingPath('requirements.0.staff.0.email');
        $review->assertJsonMissingPath('requirements.0.staff.0.phone');
        $review->assertJsonMissingPath('requirements.0.staff.0.date_of_birth');

        $this->assertDatabaseHas('audit_events', [
            'entity_id' => $requirementId,
            'action' => 'document_acknowledgment_requirement.created',
            'source_context' => AuditEvent::SOURCE_API,
        ]);
    }

    public function test_a_draft_document_cannot_be_required_and_is_not_offered(): void
    {
        $scenario = $this->organizationScenario();
        $organizer = $this->organizerFor($scenario['organization']);
        $draft = PolicyDocument::factory()->for($scenario['organization'])->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-document-acknowledgment-requirement', [
                'organization_id' => (string) $scenario['organization']->id,
                'document_type' => 'policy',
                'document_id' => (string) $draft->id,
                'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
                'scope_id' => (string) $scenario['organization']->id,
                'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a published document can be required. Publish it first, then require it.');

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$scenario['organization']->id}/document-acknowledgments")
            ->assertOk()
            ->assertJsonCount(0, 'documents');
    }

    public function test_a_staff_member_may_not_review_or_maintain_requirements(): void
    {
        $scenario = $this->organizationScenario();
        $document = $this->publishedPolicy($scenario['organization']);
        $requirement = $this->requirementFor($document, $scenario['organization']);

        $refusal = 'Only organizers may maintain and review document acknowledgment requirements.';

        $this->actingAsClient($scenario['user'])
            ->getJson("/api/organizations/{$scenario['organization']->id}/document-acknowledgments")
            ->assertForbidden()
            ->assertJsonPath('message', $refusal);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/create-document-acknowledgment-requirement', [
                'organization_id' => (string) $scenario['organization']->id,
                'document_type' => 'policy',
                'document_id' => (string) $document->id,
                'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
                'scope_id' => (string) $scenario['organization']->id,
                'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', $refusal);

        $this->actingAsClient($scenario['user'])
            ->postJson('/api/commands/set-document-acknowledgment-requirement-active', [
                'requirement_id' => (string) $requirement->id,
                'active' => false,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', $refusal);

        $this->assertTrue($requirement->refresh()->isActive());
    }

    public function test_repeating_an_acknowledgment_records_one(): void
    {
        Node::factory()->create();

        $scenario = $this->organizationScenario();
        $document = $this->publishedPolicy($scenario['organization']);
        $requirement = $this->requirementFor($document, $scenario['organization']);

        foreach (range(1, 2) as $ignored) {
            $this->actingAsClient($scenario['user'])
                ->postJson('/api/commands/acknowledge-document', [
                    'requirement_id' => (string) $requirement->id,
                ])
                ->assertOk()
                ->assertJsonPath('requirement.acknowledged', true);
        }

        $this->assertDatabaseCount('document_acknowledgments', 1);
    }

    /**
     * One staff member with an organization status and a department membership,
     * and the user who holds that profile.
     *
     * @return array{organization: Organization, department: Department, staff: Staff, user: User}
     */
    private function organizationScenario(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);

        $staff = Staff::factory()->create([
            'legal_name' => 'Wren Subject',
            'handle' => 'wren',
            'date_of_birth' => '1990-01-15',
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);

        StaffOrganizationStatus::factory()->create([
            'organization_id' => $organization->id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);

        return [
            'organization' => $organization,
            'department' => $department,
            'staff' => $staff,
            'user' => $user,
        ];
    }

    /** A user holding the organizer role in this organization. */
    private function organizerFor(Organization $organization): User
    {
        $department = Department::factory()->for($organization)->create(['name' => 'Organizer']);
        $team = Team::factory()->for($department)->create(['name' => 'Organizer Default']);

        $staff = Staff::factory()->create(['legal_name' => 'Avery Organizer']);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', 'organizer')->firstOrFail()->id,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedPolicy(Organization $organization, array $attributes = []): PolicyDocument
    {
        return PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            ...$attributes,
        ]);
    }

    private function requirementFor(
        PolicyDocument $document,
        Organization $organization,
    ): DocumentAcknowledgmentRequirement {
        return DocumentAcknowledgmentRequirement::factory()->forDocument($document)->create([
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
