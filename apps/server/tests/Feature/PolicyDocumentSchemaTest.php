<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyDocumentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_documents_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('policy_documents'));

        foreach ([
            'id',
            'organization_id',
            'scope_type',
            'scope_id',
            'title',
            'slug',
            'event_info_section',
            'markdown_source',
            'state',
            'document_revision',
            'fragment_revision',
            'published_at',
            'archived_at',
            'created_by_user_id',
            'updated_by_user_id',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('policy_documents', $column),
                "Missing column: {$column}",
            );
        }
    }

    public function test_policy_document_has_a_uuid_and_belongs_to_its_organization(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create();

        $document->refresh()->load('organization');
        $organization->refresh()->load('policyDocuments');

        $this->assertTrue(Str::isUuid($document->id));
        $this->assertTrue($document->organization->is($organization));
        $this->assertTrue($organization->policyDocuments->contains($document));
    }

    public function test_policy_document_states_and_scope_types_match_the_documented_model(): void
    {
        $this->assertSame([
            'draft',
            'published',
            'archived',
        ], PolicyDocument::states());
        $this->assertNotContains('active', PolicyDocument::states());

        $this->assertSame([
            'organization',
            'department',
            'team',
        ], PolicyDocument::scopeTypes());
    }

    public function test_policy_document_represents_all_documented_scope_targets(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();

        $organizationDocument = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $departmentDocument = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);
        $teamDocument = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_TEAM,
            'scope_id' => $team->id,
        ]);

        $organizationDocument->load('organizationScope');
        $departmentDocument->load('departmentScope');
        $teamDocument->load('teamScope');

        $this->assertTrue($organizationDocument->organizationScope->is($organization));
        $this->assertTrue($departmentDocument->departmentScope->is($department));
        $this->assertTrue($teamDocument->teamScope->is($team));
    }

    public function test_policy_document_persists_markdown_versions_and_author_references(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $document = PolicyDocument::factory()->create([
            'markdown_source' => "# Code of Conduct\n\nBe excellent to each other.",
            'document_revision' => 3,
            'fragment_revision' => 7,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);

        $document->refresh()->load(['createdBy', 'updatedBy']);

        $this->assertSame("# Code of Conduct\n\nBe excellent to each other.", $document->markdown_source);
        $this->assertSame(3, $document->document_revision);
        $this->assertSame(7, $document->fragment_revision);
        $this->assertSame('3.07', $document->version());
        $this->assertTrue($document->createdBy->is($creator));
        $this->assertTrue($document->updatedBy->is($updater));
    }

    public function test_new_policy_document_starts_at_first_document_version(): void
    {
        $document = PolicyDocument::factory()->create();

        $this->assertSame(1, $document->document_revision);
        $this->assertSame(0, $document->fragment_revision);
        $this->assertSame('1.00', $document->version());
        $this->assertTrue($document->isDraft());
    }
}
