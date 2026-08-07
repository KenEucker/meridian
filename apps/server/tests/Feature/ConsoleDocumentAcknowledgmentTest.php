<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentAcknowledgment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acknowledgment review in God Mode (M18.34; UI contract 12.9; technical spec
 * 22.2; POL-043 through POL-045).
 *
 * The evidence half of the acknowledgment requirements: who accepted which
 * version of which document, when, and on which node. Unlike the two repair
 * screens beside it, this one spans organizations on the console permission
 * alone — an acknowledgment is an attestation about a document rather than a
 * personal account or an Incident Command record.
 */
class ConsoleDocumentAcknowledgmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_requires_its_console_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.document-acknowledgments'))
            ->assertForbidden();
    }

    public function test_it_names_who_accepted_which_document_at_which_revision(): void
    {
        $organization = Organization::factory()->create();
        $document = $this->policyFor($organization, 'Radio Discipline');
        $staff = Staff::factory()->create(['handle' => 'Sparks']);

        DocumentAcknowledgment::factory()
            ->forDocument($document)
            ->forStaff($staff)
            ->create(['document_revision' => 47, 'fragment_revision' => 12]);

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.document-acknowledgments'));

        $response->assertOk();
        $response->assertSee('Radio Discipline');
        $response->assertSee('Sparks');
        // POL-045: the revision is the point. An acceptance is an acceptance of
        // a version, and the fragment revision moves independently of it.
        $response->assertSee('47');
        $response->assertSee('12');
    }

    /** Both kinds of document are acknowledged, and both belong here. */
    public function test_it_carries_procedure_acknowledgments_as_well_as_policy_ones(): void
    {
        $organization = Organization::factory()->create();

        $procedure = ProcedureDocument::factory()->for($organization)->published()->create([
            'title' => 'Gate Closure Procedure',
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        DocumentAcknowledgment::factory()->forDocument($procedure)->create();

        $this->actingAs($this->godModeUser())
            ->get(route('platform.document-acknowledgments'))
            ->assertOk()
            ->assertSee('Gate Closure Procedure');
    }

    /** Console access spans the node, which is what makes this the repair view. */
    public function test_it_spans_organizations(): void
    {
        $northwood = Organization::factory()->create();
        $cascadia = Organization::factory()->create();

        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($northwood, 'Northwood Conduct Policy'))
            ->create();
        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($cascadia, 'Cascadia Conduct Policy'))
            ->create();

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.document-acknowledgments'));

        $response->assertOk();
        $response->assertSee('Northwood Conduct Policy');
        $response->assertSee('Cascadia Conduct Policy');
    }

    /**
     * An acknowledgment records the scope it was asked in, not an organization,
     * so narrowing to an organization has to reach its departments' rows too —
     * a reviewer asking about an organization means all of it.
     */
    public function test_the_organization_filter_covers_department_scoped_acknowledgments(): void
    {
        $northwood = Organization::factory()->create();
        $cascadia = Organization::factory()->create();
        $rangers = Department::factory()->for($northwood)->create();

        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($northwood, 'Northwood Conduct Policy'))
            ->create();

        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($northwood, 'Ranger Field Manual'))
            ->create([
                'scope_type' => DocumentAcknowledgment::SCOPE_DEPARTMENT,
                'scope_id' => $rangers->id,
            ]);

        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($cascadia, 'Cascadia Conduct Policy'))
            ->create();

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.document-acknowledgments', ['scope_organization' => $northwood->id]));

        $response->assertOk();
        $response->assertSee('Northwood Conduct Policy');
        $response->assertSee('Ranger Field Manual');
        $response->assertDontSee('Cascadia Conduct Policy');
    }

    public function test_the_department_filter_narrows_to_what_was_asked_in_that_department(): void
    {
        $organization = Organization::factory()->create();
        $rangers = Department::factory()->for($organization)->create();
        $gate = Department::factory()->for($organization)->create();

        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($organization, 'Ranger Field Manual'))
            ->create([
                'scope_type' => DocumentAcknowledgment::SCOPE_DEPARTMENT,
                'scope_id' => $rangers->id,
            ]);

        DocumentAcknowledgment::factory()
            ->forDocument($this->policyFor($organization, 'Gate Handbook'))
            ->create([
                'scope_type' => DocumentAcknowledgment::SCOPE_DEPARTMENT,
                'scope_id' => $gate->id,
            ]);

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.document-acknowledgments', ['scope_department' => $rangers->id]));

        $response->assertOk();
        $response->assertSee('Ranger Field Manual');
        $response->assertDontSee('Gate Handbook');
    }

    /**
     * POL-026 asks a requirement at an organization or a department and never
     * at a team, so the team control is absent rather than present and always
     * empty.
     */
    public function test_it_offers_no_team_filter(): void
    {
        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.document-acknowledgments'));

        $response->assertOk();
        $response->assertSee('scope_organization');
        $response->assertSee('scope_department');
        $response->assertDontSee('scope_team');
    }

    /**
     * Immutable at the model, so there is no write path for a screen to offer.
     * POL-045 depends on it: an acceptance of revision 4 has to keep saying
     * revision 4 after the document reaches revision 5.
     */
    public function test_an_acknowledgment_cannot_be_edited_or_removed(): void
    {
        $acknowledgment = DocumentAcknowledgment::factory()->create();

        $this->expectException(\RuntimeException::class);

        $acknowledgment->update(['document_revision' => 99]);
    }

    private function policyFor(Organization $organization, string $title): PolicyDocument
    {
        return PolicyDocument::factory()->for($organization)->published()->create([
            'title' => $title,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.document-acknowledgments' => true,
            ],
        ]);
    }
}
