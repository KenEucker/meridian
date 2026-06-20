<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Team;
use App\Services\Documents\BrokenDocumentFragmentReferenceException;
use App\Services\Documents\DocumentFragmentReferenceParser;
use App\Services\Documents\DocumentFragmentReferenceService;
use App\Services\Documents\IneligibleDocumentFragmentReferenceException;
use App\Services\Documents\MalformedDocumentFragmentReferenceException;
use App\Services\Documents\NestedDocumentFragmentReferenceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentFragmentReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_fragment_references_table_has_the_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('document_fragment_references'));

        foreach ([
            'id',
            'document_type',
            'document_id',
            'fragment_id',
            'token',
            'fragment_version_at_last_edit',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('document_fragment_references', $column),
                "Missing column: {$column}",
            );
        }
    }

    public function test_parser_returns_unique_author_facing_slug_tokens(): void
    {
        $references = app(DocumentFragmentReferenceParser::class)->parse(<<<'MARKDOWN'
            # Expectations

            {{fragment:org-behavioral-agreement}}
            {{fragment:radio-expectations}}
            {{fragment:org-behavioral-agreement}}
            MARKDOWN);

        $this->assertSame([
            [
                'token' => '{{fragment:org-behavioral-agreement}}',
                'slug' => 'org-behavioral-agreement',
            ],
            [
                'token' => '{{fragment:radio-expectations}}',
                'slug' => 'radio-expectations',
            ],
        ], $references);
    }

    public function test_malformed_fragment_tokens_are_rejected(): void
    {
        $parser = app(DocumentFragmentReferenceParser::class);

        foreach ([
            '{{fragment:}}',
            '{{fragment:radio expectations}}',
            '{{fragment:radio-expectations}',
        ] as $markdown) {
            try {
                $parser->parse($markdown);
                $this->fail("Expected malformed token '{$markdown}' to be rejected.");
            } catch (MalformedDocumentFragmentReferenceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_synchronize_resolves_policy_tokens_to_fragment_uuids_and_versions(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'name' => 'Behavioral agreement',
            'slug' => 'org-behavioral-agreement',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => "# Expectations\n\n{{fragment:org-behavioral-agreement}}",
        ]);

        $references = app(DocumentFragmentReferenceService::class)->synchronize($document);

        $this->assertCount(1, $references);
        $this->assertSame(DocumentFragmentReference::DOCUMENT_TYPE_POLICY, $references[0]->document_type);
        $this->assertSame($document->id, $references[0]->document_id);
        $this->assertSame($fragment->id, $references[0]->fragment_id);
        $this->assertSame('{{fragment:org-behavioral-agreement}}', $references[0]->token);
        $this->assertSame(1, $references[0]->fragment_version_at_last_edit);

        $document->load('fragmentReferences.fragment');
        $fragment->load('references');

        $this->assertTrue($document->fragmentReferences->sole()->fragment->is($fragment));
        $this->assertTrue($fragment->references->sole()->is($references[0]));
    }

    public function test_synchronize_records_procedure_references_and_removes_stale_references(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'check-in-steps',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $document = ProcedureDocument::factory()->for($organization)->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:check-in-steps}}',
        ]);
        $service = app(DocumentFragmentReferenceService::class);

        $reference = $service->synchronize($document)[0];

        $this->assertSame(DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE, $reference->document_type);
        $this->assertSame(1, $reference->fragment_version_at_last_edit);

        $fragment->update(['markdown_source' => 'Updated check-in steps.']);
        $service->synchronize($document);

        $this->assertSame(2, $reference->refresh()->fragment_version_at_last_edit);

        $document->update(['markdown_source' => '# Standalone procedure']);
        $service->synchronize($document);

        $this->assertDatabaseMissing('document_fragment_references', ['id' => $reference->id]);
    }

    public function test_broken_and_ambiguous_references_are_blocked_without_storing_rows(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => '{{fragment:missing-fragment}}',
        ]);
        $service = app(DocumentFragmentReferenceService::class);

        try {
            $service->synchronize($document);
            $this->fail('Expected missing fragment to be rejected.');
        } catch (BrokenDocumentFragmentReferenceException) {
            $this->assertDatabaseCount('document_fragment_references', 0);
        }

        DocumentFragment::factory()->for($organization)->count(2)->create([
            'slug' => 'duplicate-fragment',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $document->update(['markdown_source' => '{{fragment:duplicate-fragment}}']);

        $this->expectException(BrokenDocumentFragmentReferenceException::class);
        $service->synchronize($document);
    }

    public function test_fragment_scope_rules_allow_only_the_documented_downward_reuse(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $otherDepartment = Department::factory()->for($organization)->create();

        $organizationFragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'organization-fragment',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $departmentFragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'department-fragment',
            'scope_type' => DocumentFragment::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);
        $teamFragment = DocumentFragment::factory()->for($organization)->create([
            'slug' => 'team-fragment',
            'scope_type' => DocumentFragment::SCOPE_TEAM,
            'scope_id' => $team->id,
        ]);
        $service = app(DocumentFragmentReferenceService::class);

        $departmentDocument = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'markdown_source' => '{{fragment:organization-fragment}} {{fragment:department-fragment}}',
        ]);
        $teamDocument = ProcedureDocument::factory()->for($organization)->create([
            'scope_type' => ProcedureDocument::SCOPE_TEAM,
            'scope_id' => $team->id,
            'markdown_source' => '{{fragment:organization-fragment}} {{fragment:department-fragment}} {{fragment:team-fragment}}',
        ]);

        $this->assertCount(2, $service->synchronize($departmentDocument));
        $this->assertCount(3, $service->synchronize($teamDocument));

        $otherDepartmentDocument = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $otherDepartment->id,
            'markdown_source' => '{{fragment:department-fragment}}',
        ]);
        $teamFragmentOnDepartment = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'markdown_source' => '{{fragment:team-fragment}}',
        ]);

        foreach ([$otherDepartmentDocument, $teamFragmentOnDepartment] as $document) {
            try {
                $service->synchronize($document);
                $this->fail('Expected an out-of-scope fragment to be rejected.');
            } catch (IneligibleDocumentFragmentReferenceException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertEqualsCanonicalizing(
            [$organizationFragment->id, $departmentFragment->id],
            $departmentDocument->fragmentReferences()->pluck('fragment_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$organizationFragment->id, $departmentFragment->id, $teamFragment->id],
            $teamDocument->fragmentReferences()->pluck('fragment_id')->all(),
        );
    }

    public function test_fragments_cannot_contain_reference_tokens(): void
    {
        $this->expectException(NestedDocumentFragmentReferenceException::class);

        DocumentFragment::factory()->create([
            'markdown_source' => 'Use {{fragment:organization-fragment}} here.',
        ]);
    }
}
