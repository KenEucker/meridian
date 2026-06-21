<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentFragment;
use App\Models\DocumentVersionSnapshot;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\User;
use App\Services\Documents\DocumentAcknowledgmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DocumentAcknowledgmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledgment_and_snapshot_tables_have_the_documented_fields(): void
    {
        foreach ([
            'id',
            'user_id',
            'staff_id',
            'document_type',
            'document_id',
            'document_revision',
            'fragment_revision',
            'scope_type',
            'scope_id',
            'acknowledged_at',
            'accepted_by_node_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('document_acknowledgments', $column),
                "document_acknowledgments should have a {$column} column.",
            );
        }

        foreach ([
            'id',
            'document_type',
            'document_id',
            'document_revision',
            'fragment_revision',
            'markdown_source_snapshot',
            'resolved_markdown_snapshot',
            'snapshot_reason',
            'created_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('document_version_snapshots', $column),
                "document_version_snapshots should have a {$column} column.",
            );
        }

        $this->assertFalse(Schema::hasColumn('document_acknowledgments', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('document_acknowledgments', 'markdown_source_snapshot'));
        $this->assertFalse(Schema::hasColumn('document_acknowledgments', 'resolved_markdown_snapshot'));
        $this->assertFalse(Schema::hasColumn('document_version_snapshots', 'updated_at'));
    }

    public function test_connected_policy_acknowledgment_records_version_snapshot_relationships_and_audit(): void
    {
        $organization = Organization::factory()->create();
        DocumentFragment::factory()->for($organization)->create([
            'slug' => 'radio-expectations',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'markdown_source' => 'Use the radio **only when needed**.',
        ]);
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'document_revision' => 3,
            'fragment_revision' => 2,
            'markdown_source' => "# Radio safety\n\n{{fragment:radio-expectations}}",
        ]);
        $requirement = DocumentAcknowledgmentRequirement::factory()->forDocument($document)->create([
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'requirement_context' => DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        ]);
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $node = Node::factory()->create(['organization_id' => $organization->id]);

        $acknowledgment = app(DocumentAcknowledgmentService::class)->acknowledge(
            $requirement,
            $user,
            $node,
            $staff,
        );

        $acknowledgment->refresh()->load(['user', 'staff', 'acceptedByNode', 'document']);
        $document->refresh()->load(['acknowledgments', 'versionSnapshots']);
        $user->refresh()->load('documentAcknowledgments');
        $staff->refresh()->load('documentAcknowledgments');
        $node->refresh()->load('acceptedDocumentAcknowledgments');
        $snapshot = DocumentVersionSnapshot::query()->sole();
        $audit = AuditEvent::query()
            ->forEntity($acknowledgment->getMorphClass(), $acknowledgment->id)
            ->sole();

        $this->assertSame(DocumentAcknowledgment::DOCUMENT_TYPE_POLICY, $acknowledgment->document_type);
        $this->assertSame($document->id, $acknowledgment->document_id);
        $this->assertSame(3, $acknowledgment->document_revision);
        $this->assertSame(2, $acknowledgment->fragment_revision);
        $this->assertSame(DocumentAcknowledgment::SCOPE_ORGANIZATION, $acknowledgment->scope_type);
        $this->assertSame($organization->id, $acknowledgment->scope_id);
        $this->assertSame($staff->id, $acknowledgment->staff_id);
        $this->assertSame($node->id, $acknowledgment->accepted_by_node_id);
        $this->assertNotNull($acknowledgment->acknowledged_at);
        $this->assertTrue($acknowledgment->user->is($user));
        $this->assertTrue($acknowledgment->staff->is($staff));
        $this->assertTrue($acknowledgment->acceptedByNode->is($node));
        $this->assertTrue($acknowledgment->document->is($document));
        $this->assertTrue($document->acknowledgments->contains($acknowledgment));
        $this->assertTrue($user->documentAcknowledgments->contains($acknowledgment));
        $this->assertTrue($staff->documentAcknowledgments->contains($acknowledgment));
        $this->assertTrue($node->acceptedDocumentAcknowledgments->contains($acknowledgment));

        $this->assertSame($document->markdown_source, $snapshot->markdown_source_snapshot);
        $this->assertSame("# Radio safety\n\nUse the radio **only when needed**.", $snapshot->resolved_markdown_snapshot);
        $this->assertStringNotContainsString('{{fragment:radio-expectations}}', $snapshot->resolved_markdown_snapshot);
        $this->assertSame('acknowledgment', $snapshot->snapshot_reason);
        $this->assertTrue($document->versionSnapshots->contains($snapshot));

        $this->assertSame('document_acknowledgment.accepted', $audit->action);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame($node->id, $audit->actor_node_id);
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame(3, $audit->after_json['document_revision']);
        $this->assertSame(2, $audit->after_json['fragment_revision']);
    }

    public function test_connected_procedure_acknowledgment_copies_department_requirement_scope(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $document = ProcedureDocument::factory()->for($organization)->published()->create([
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'document_revision' => 4,
            'fragment_revision' => 1,
        ]);
        $requirement = DocumentAcknowledgmentRequirement::factory()->forDocument($document)->training()->create([
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);
        $user = User::factory()->create();
        $node = Node::factory()->create(['organization_id' => $organization->id]);

        $acknowledgment = app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node);

        $this->assertSame(DocumentAcknowledgment::DOCUMENT_TYPE_PROCEDURE, $acknowledgment->document_type);
        $this->assertSame($document->id, $acknowledgment->document_id);
        $this->assertSame(4, $acknowledgment->document_revision);
        $this->assertSame(1, $acknowledgment->fragment_revision);
        $this->assertSame(DocumentAcknowledgment::SCOPE_DEPARTMENT, $acknowledgment->scope_type);
        $this->assertSame($department->id, $acknowledgment->scope_id);
        $this->assertNull($acknowledgment->staff_id);
    }

    public function test_repeat_acknowledgment_is_idempotent_and_later_document_changes_do_not_re_require_it(): void
    {
        [$requirement, $user, $node, $document] = $this->acknowledgmentInputs();
        $service = app(DocumentAcknowledgmentService::class);

        $first = $service->acknowledge($requirement, $user, $node);
        $second = $service->acknowledge($requirement, $user, $node);
        $document->update(['fragment_revision' => 1]);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('document_acknowledgments', 1);
        $this->assertDatabaseCount('document_version_snapshots', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame(0, $first->refresh()->fragment_revision);
        $this->assertSame(1, $document->refresh()->fragment_revision);
    }

    public function test_inactive_requirement_or_draft_document_is_rejected_without_writes(): void
    {
        [$requirement, $user, $node, $document] = $this->acknowledgmentInputs();
        $requirement->update(['active' => false]);

        try {
            app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node);
            $this->fail('Inactive requirements must not be acknowledged.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The acknowledgment requirement is inactive.', $exception->getMessage());
        }

        $requirement->update(['active' => true]);
        $document->update(['state' => PolicyDocument::STATE_DRAFT]);

        try {
            app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node);
            $this->fail('Draft documents must not be acknowledged.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Only published documents may be acknowledged.', $exception->getMessage());
        }

        $this->assertDatabaseCount('document_acknowledgments', 0);
        $this->assertDatabaseCount('document_version_snapshots', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_cross_organization_documents_nodes_and_unlinked_staff_are_rejected_without_writes(): void
    {
        [$requirement, $user, $node] = $this->acknowledgmentInputs();
        $otherOrganization = Organization::factory()->create();
        $crossOrganizationDocument = PolicyDocument::factory()->for($otherOrganization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $otherOrganization->id,
        ]);
        $requirement->update(['document_id' => $crossOrganizationDocument->id]);

        try {
            app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node);
            $this->fail('Cross-organization documents must not be acknowledged.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The acknowledgment document must belong to the requirement organization.', $exception->getMessage());
        }

        $requirement->update(['document_id' => PolicyDocument::factory()->for($requirement->organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $requirement->organization_id,
        ])->id]);
        $otherOrganizationNode = Node::factory()->create(['organization_id' => $otherOrganization->id]);

        try {
            app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $otherOrganizationNode);
            $this->fail('Nodes from another organization must not accept acknowledgments.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The accepting node must belong to the acknowledgment organization.', $exception->getMessage());
        }

        $unlinkedStaff = Staff::factory()->create();

        try {
            app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node, $unlinkedStaff);
            $this->fail('Unlinked staff profiles must not be recorded for a user acknowledgment.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The acknowledgment staff profile must belong to the acknowledging user.', $exception->getMessage());
        }

        $this->assertDatabaseCount('document_acknowledgments', 0);
        $this->assertDatabaseCount('document_version_snapshots', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoked_nodes_and_acknowledgment_history_cannot_be_mutated(): void
    {
        [$requirement, $user, $node] = $this->acknowledgmentInputs();
        $node->update(['revoked_at' => now()]);

        try {
            app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node);
            $this->fail('Revoked nodes must not accept acknowledgments.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Acknowledgments must be accepted by an active connected node.', $exception->getMessage());
        }

        $node->update(['revoked_at' => null]);
        $acknowledgment = app(DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node);
        $snapshot = DocumentVersionSnapshot::query()->sole();

        try {
            $snapshot->delete();
            $this->fail('Document version snapshots must be immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Document version snapshots are immutable and cannot be deleted.', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Document acknowledgments are immutable and cannot be updated.');

        $acknowledgment->update(['scope_type' => DocumentAcknowledgment::SCOPE_DEPARTMENT]);
    }

    /**
     * @return array{DocumentAcknowledgmentRequirement, User, Node, PolicyDocument}
     */
    private function acknowledgmentInputs(): array
    {
        $organization = Organization::factory()->create();
        $document = PolicyDocument::factory()->for($organization)->published()->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $requirement = DocumentAcknowledgmentRequirement::factory()->forDocument($document)->create([
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        return [
            $requirement,
            User::factory()->create(),
            Node::factory()->create(['organization_id' => $organization->id]),
            $document,
        ];
    }
}
