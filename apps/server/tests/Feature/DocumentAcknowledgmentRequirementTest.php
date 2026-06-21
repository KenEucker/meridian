<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Team;
use App\Services\Documents\DocumentAcknowledgmentRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentAcknowledgmentRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_acknowledgment_requirements_table_has_the_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('document_acknowledgment_requirements'));

        foreach ([
            'id',
            'organization_id',
            'scope_type',
            'scope_id',
            'document_type',
            'document_id',
            'requirement_context',
            'active',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('document_acknowledgment_requirements', $column),
                "Missing column: {$column}",
            );
        }
    }

    public function test_factory_creates_an_active_organization_scoped_policy_requirement(): void
    {
        $requirement = DocumentAcknowledgmentRequirement::factory()->create();

        $requirement->load(['organization', 'organizationScope', 'document']);

        $this->assertTrue($requirement->organization->is($requirement->organizationScope));
        $this->assertInstanceOf(PolicyDocument::class, $requirement->document);
        $this->assertSame($requirement->organization_id, $requirement->document->organization_id);
        $this->assertTrue($requirement->isActive());
    }

    public function test_organization_scoped_signup_requirement_references_a_policy_document(): void
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $requirement = app(DocumentAcknowledgmentRequirementService::class)->create(
            $organization,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            $document->id,
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            $organization->id,
            DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        );

        $requirement->refresh()->load(['organization', 'organizationScope', 'document']);
        $organization->refresh()->load('documentAcknowledgmentRequirements');
        $document->refresh()->load('acknowledgmentRequirements');

        $this->assertTrue(Str::isUuid($requirement->id));
        $this->assertTrue($requirement->organization->is($organization));
        $this->assertTrue($requirement->organizationScope->is($organization));
        $this->assertTrue($requirement->document->is($document));
        $this->assertSame(DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP, $requirement->requirement_context);
        $this->assertTrue($requirement->isActive());
        $this->assertTrue($organization->documentAcknowledgmentRequirements->contains($requirement));
        $this->assertTrue($document->acknowledgmentRequirements->contains($requirement));
    }

    public function test_department_scoped_training_requirement_references_a_procedure_document(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $document = ProcedureDocument::factory()->for($organization)->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);

        $requirement = app(DocumentAcknowledgmentRequirementService::class)->create(
            $organization,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE,
            $document->id,
            DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT,
            $department->id,
            DocumentAcknowledgmentRequirement::CONTEXT_TRAINING,
            active: false,
        );

        $requirement->refresh()->load(['departmentScope', 'document']);
        $department->refresh()->load('documentAcknowledgmentRequirements');

        $this->assertTrue($requirement->departmentScope->is($department));
        $this->assertTrue($requirement->document->is($document));
        $this->assertSame(DocumentAcknowledgmentRequirement::CONTEXT_TRAINING, $requirement->requirement_context);
        $this->assertFalse($requirement->isActive());
        $this->assertFalse(DocumentAcknowledgmentRequirement::query()->active()->whereKey($requirement->id)->exists());
        $this->assertTrue($department->documentAcknowledgmentRequirements->contains($requirement));
    }

    public function test_only_documented_document_types_scopes_and_contexts_are_available(): void
    {
        $this->assertSame([
            'policy',
            'procedure',
        ], DocumentAcknowledgmentRequirement::documentTypes());
        $this->assertSame([
            'organization',
            'department',
        ], DocumentAcknowledgmentRequirement::scopeTypes());
        $this->assertSame([
            'signup',
            'training',
        ], DocumentAcknowledgmentRequirement::requirementContexts());
        $this->assertNotContains('team', DocumentAcknowledgmentRequirement::scopeTypes());
        $this->assertNotContains('shift_signup', DocumentAcknowledgmentRequirement::requirementContexts());
        $this->assertNotContains('credential_eligibility', DocumentAcknowledgmentRequirement::requirementContexts());
    }

    public function test_requirement_creation_rejects_an_unsupported_document_type(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported acknowledgment document type.');

        app(DocumentAcknowledgmentRequirementService::class)->create(
            $organization,
            'guide',
            (string) Str::uuid(),
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            $organization->id,
            DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        );
    }

    public function test_requirement_creation_rejects_team_scope_and_unsupported_context(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $document = PolicyDocument::factory()->for($organization)->create();
        $service = app(DocumentAcknowledgmentRequirementService::class);

        try {
            $service->create(
                $organization,
                DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
                $document->id,
                'team',
                $team->id,
                DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
            );
            $this->fail('Team-scoped acknowledgment requirement was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Acknowledgment requirements must use organization or department scope.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Acknowledgment requirements may only be used during signup or training.');

        $service->create(
            $organization,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            $document->id,
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            $organization->id,
            'shift_signup',
        );
    }

    public function test_requirement_creation_rejects_missing_or_cross_organization_documents(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherDocument = ProcedureDocument::factory()->for($otherOrganization)->create();
        $service = app(DocumentAcknowledgmentRequirementService::class);

        try {
            $service->create(
                $organization,
                DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
                (string) Str::uuid(),
                DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
                $organization->id,
                DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
            );
            $this->fail('Missing acknowledgment document was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The selected acknowledgment document does not exist.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected acknowledgment document must belong to the requirement organization.');

        $service->create(
            $organization,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE,
            $otherDocument->id,
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            $organization->id,
            DocumentAcknowledgmentRequirement::CONTEXT_TRAINING,
        );
    }

    public function test_requirement_creation_rejects_a_scope_target_from_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherDepartment = Department::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The scope target must belong to the requirement organization and scope type.');

        app(DocumentAcknowledgmentRequirementService::class)->create(
            $organization,
            DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            $document->id,
            DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT,
            $otherDepartment->id,
            DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        );
    }
}
