<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentFragmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_fragments_table_has_the_documented_fields_without_lifecycle_state(): void
    {
        $this->assertTrue(Schema::hasTable('document_fragments'));

        foreach ([
            'id',
            'organization_id',
            'scope_type',
            'scope_id',
            'name',
            'slug',
            'markdown_source',
            'version',
            'created_by_user_id',
            'updated_by_user_id',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('document_fragments', $column),
                "Missing column: {$column}",
            );
        }

        $this->assertFalse(Schema::hasColumn('document_fragments', 'state'));
        $this->assertFalse(Schema::hasColumn('document_fragments', 'published_at'));
        $this->assertFalse(Schema::hasColumn('document_fragments', 'archived_at'));
    }

    public function test_document_fragment_has_a_uuid_and_belongs_to_its_organization(): void
    {
        $organization = Organization::factory()->create();
        $fragment = DocumentFragment::factory()->for($organization)->create();

        $fragment->refresh()->load('organization');
        $organization->refresh()->load('documentFragments');

        $this->assertTrue(Str::isUuid($fragment->id));
        $this->assertTrue($fragment->organization->is($organization));
        $this->assertTrue($organization->documentFragments->contains($fragment));
    }

    public function test_document_fragment_supports_all_documented_scope_targets(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();

        $organizationFragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $departmentFragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);
        $teamFragment = DocumentFragment::factory()->for($organization)->create([
            'scope_type' => DocumentFragment::SCOPE_TEAM,
            'scope_id' => $team->id,
        ]);

        $organizationFragment->load('organizationScope');
        $departmentFragment->load('departmentScope');
        $teamFragment->load('teamScope');

        $this->assertSame([
            'organization',
            'department',
            'team',
        ], DocumentFragment::scopeTypes());
        $this->assertTrue($organizationFragment->organizationScope->is($organization));
        $this->assertTrue($departmentFragment->departmentScope->is($department));
        $this->assertTrue($teamFragment->teamScope->is($team));
    }

    public function test_document_fragment_persists_markdown_and_author_references(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $fragment = DocumentFragment::factory()->create([
            'name' => 'Behavioral agreement',
            'slug' => 'behavioral-agreement',
            'markdown_source' => "## Be excellent\n\nTreat everyone with respect.",
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $updater->id,
        ]);

        $fragment->refresh()->load(['createdBy', 'updatedBy']);

        $this->assertSame('Behavioral agreement', $fragment->name);
        $this->assertSame('behavioral-agreement', $fragment->slug);
        $this->assertSame("## Be excellent\n\nTreat everyone with respect.", $fragment->markdown_source);
        $this->assertSame(1, $fragment->version);
        $this->assertTrue($fragment->createdBy->is($creator));
        $this->assertTrue($fragment->updatedBy->is($updater));
    }

    public function test_markdown_changes_increment_the_fragment_version_while_metadata_changes_do_not(): void
    {
        $fragment = DocumentFragment::factory()->create([
            'markdown_source' => 'Initial fragment text.',
        ]);

        $this->assertSame(1, $fragment->version);

        $fragment->name = 'Renamed fragment';
        $fragment->save();

        $this->assertSame(1, $fragment->refresh()->version);

        $fragment->markdown_source = 'Updated fragment text.';
        $fragment->save();

        $this->assertSame(2, $fragment->refresh()->version);

        $fragment->markdown_source = 'Updated fragment text again.';
        $fragment->save();

        $this->assertSame(3, $fragment->refresh()->version);
    }

    public function test_fragment_version_cannot_be_manually_changed_without_a_markdown_change(): void
    {
        $fragment = DocumentFragment::factory()->create();

        $fragment->version = 42;
        $fragment->save();

        $this->assertSame(1, $fragment->refresh()->version);
    }
}
