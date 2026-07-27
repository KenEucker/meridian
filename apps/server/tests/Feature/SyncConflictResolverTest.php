<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\SignedNodeOperation;
use App\Services\Node\SyncConflictException;
use App\Services\Node\SyncConflictResolutionException;
use App\Services\Node\SyncConflictResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Domain coverage for resolving a God-mode sync conflict by accepting the
 * on-site or the central version, with audit (technical spec 10.3; data/API
 * 7.5, 14.2).
 *
 * Unless a test says otherwise, this install is the central node and the
 * conflicted operation arrived from the on-site node, which is the shape
 * technical spec 10.2 describes for operations pushed back to central.
 */
class SyncConflictResolverTest extends TestCase
{
    use RefreshDatabase;

    private SyncConflictResolver $resolver;

    private Node $localNode;

    private Node $remoteNode;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(SyncConflictResolver::class);
        $this->localNode = Node::factory()->central()->create();
        $this->remoteNode = Node::factory()->onsite()->remote()->create();
        $this->reviewer = User::factory()->create();
    }

    public function test_accepting_the_on_site_version_on_central_applies_the_operation(): void
    {
        $applier = $this->registerApplier();
        [$conflict, $operation] = $this->conflict();

        $resolved = $this->resolver->acceptOnsite($conflict, $this->reviewer);

        $this->assertSame(SyncConflict::STATUS_RESOLVED, $resolved->status);
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_ONSITE, $resolved->resolution);
        $this->assertSame((string) $this->reviewer->getKey(), (string) $resolved->reviewed_by_user_id);
        $this->assertNotNull($resolved->reviewed_at);

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->status);
        $this->assertNotNull($operation->applied_at);
        $this->assertNull($operation->failure_reason);
        $this->assertSame(1, $applier->applied);
    }

    public function test_accepting_the_central_version_on_central_keeps_local_state(): void
    {
        $applier = $this->registerApplier();
        [$conflict, $operation] = $this->conflict();

        $resolved = $this->resolver->acceptCentral($conflict, $this->reviewer);

        $this->assertSame(SyncConflict::STATUS_RESOLVED, $resolved->status);
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_CENTRAL, $resolved->resolution);

        $operation->refresh();

        $this->assertSame(0, $applier->applied, 'Keeping the local version must not write remote state.');
        $this->assertSame(NodeOperation::STATUS_CONFLICTED, $operation->status);
        $this->assertNull($operation->applied_at);
        $this->assertStringContainsString('Superseded by God-mode conflict resolution', (string) $operation->failure_reason);
    }

    /**
     * The same two choices have to mean the same thing on both nodes, so the
     * side that gets applied flips when this install is the on-site node.
     */
    public function test_accepting_the_central_version_on_the_on_site_node_applies_the_operation(): void
    {
        $this->localNode->forceFill(['node_role' => Node::ROLE_ONSITE])->save();
        $this->remoteNode->forceFill(['node_role' => Node::ROLE_CENTRAL])->save();

        $applier = $this->registerApplier();
        [$conflict, $operation] = $this->conflict();

        $this->resolver->acceptCentral($conflict, $this->reviewer);

        $this->assertSame(1, $applier->applied);
        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->fresh()->status);
    }

    public function test_accepting_the_on_site_version_on_the_on_site_node_keeps_local_state(): void
    {
        $this->localNode->forceFill(['node_role' => Node::ROLE_ONSITE])->save();
        $this->remoteNode->forceFill(['node_role' => Node::ROLE_CENTRAL])->save();

        $applier = $this->registerApplier();
        [$conflict, $operation] = $this->conflict();

        $this->resolver->acceptOnsite($conflict, $this->reviewer);

        $this->assertSame(0, $applier->applied);
        $this->assertSame(NodeOperation::STATUS_CONFLICTED, $operation->fresh()->status);
    }

    public function test_resolution_is_audited_with_the_reviewer_and_the_accepted_side(): void
    {
        $this->registerApplier();
        [$conflict, $operation] = $this->conflict();

        $this->resolver->acceptOnsite($conflict, $this->reviewer);

        $audit = AuditEvent::query()
            ->where('action', SyncConflictResolver::AUDIT_RESOLVED)
            ->sole();

        $this->assertSame((string) $conflict->getKey(), $audit->entity_id);
        $this->assertSame((string) $this->reviewer->getKey(), (string) $audit->actor_user_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame(SyncConflict::STATUS_OPEN, $audit->before_json['status']);
        $this->assertNull($audit->before_json['resolution']);
        $this->assertSame(SyncConflict::STATUS_RESOLVED, $audit->after_json['status']);
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_ONSITE, $audit->after_json['resolution']);
        $this->assertSame(SyncConflictResolver::SIDE_REMOTE, $audit->after_json['accepted_side']);
        $this->assertSame((string) $operation->uuid, $audit->after_json['operation_uuid']);
        $this->assertSame(['status' => 'closed'], $audit->after_json['accepted_value']);
        $this->assertNotNull($audit->reason);
    }

    public function test_the_audited_accepted_value_is_the_local_value_when_local_wins(): void
    {
        $this->registerApplier();
        [$conflict] = $this->conflict();

        $this->resolver->acceptCentral($conflict, $this->reviewer);

        $audit = AuditEvent::query()->where('action', SyncConflictResolver::AUDIT_RESOLVED)->sole();

        $this->assertSame(SyncConflictResolver::SIDE_LOCAL, $audit->after_json['accepted_side']);
        $this->assertSame(['status' => 'open'], $audit->after_json['accepted_value']);
    }

    public function test_an_event_scoped_audit_entry_carries_the_event(): void
    {
        $this->registerApplier();
        $event = Event::factory()->create();
        [$conflict] = $this->conflict(['event_id' => (string) $event->getKey()]);

        $this->resolver->acceptOnsite($conflict, $this->reviewer);

        $audit = AuditEvent::query()->where('action', SyncConflictResolver::AUDIT_RESOLVED)->sole();

        $this->assertSame((string) $event->getKey(), (string) $audit->event_id);
    }

    public function test_a_resolved_conflict_cannot_be_resolved_again(): void
    {
        $this->registerApplier();
        [$conflict] = $this->conflict();

        $this->resolver->acceptOnsite($conflict, $this->reviewer);

        try {
            $this->resolver->acceptCentral($conflict->fresh(), $this->reviewer);
            $this->fail('A resolved conflict should not accept a second decision.');
        } catch (SyncConflictResolutionException $exception) {
            $this->assertSame(SyncConflictResolutionException::REASON_ALREADY_RESOLVED, $exception->reason);
        }

        $this->assertSame(
            SyncConflict::RESOLUTION_ACCEPT_ONSITE,
            $conflict->fresh()->resolution,
            'The first decision stands.',
        );
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_an_unknown_resolution_is_refused(): void
    {
        [$conflict] = $this->conflict();

        try {
            $this->resolver->resolve($conflict, 'accept_whichever', $this->reviewer);
            $this->fail('Only accept on-site and accept central are resolutions.');
        } catch (SyncConflictResolutionException $exception) {
            $this->assertSame(SyncConflictResolutionException::REASON_UNKNOWN_RESOLUTION, $exception->reason);
        }

        $this->assertTrue($conflict->fresh()->isOpen());
    }

    /**
     * Accept on-site and accept central only name different versions when one of
     * the two nodes is central.
     */
    public function test_a_conflict_between_two_non_central_nodes_is_refused(): void
    {
        $this->localNode->forceFill(['node_role' => Node::ROLE_ONSITE])->save();

        [$conflict] = $this->conflict();

        try {
            $this->resolver->acceptOnsite($conflict, $this->reviewer);
            $this->fail('Two on-site nodes leave both choices naming the same version.');
        } catch (SyncConflictResolutionException $exception) {
            $this->assertSame(
                SyncConflictResolutionException::REASON_SIDES_NOT_DISTINGUISHABLE,
                $exception->reason,
            );
        }

        $this->assertTrue($conflict->fresh()->isOpen());
    }

    /**
     * A resolution that could not write the accepted version must not claim the
     * disagreement was settled.
     */
    public function test_a_conflict_stays_open_when_the_accepted_version_cannot_be_applied(): void
    {
        $applier = $this->registerApplier();
        $applier->failWith = 'The referenced Field Report is missing.';

        [$conflict, $operation] = $this->conflict();

        try {
            $this->resolver->acceptOnsite($conflict, $this->reviewer);
            $this->fail('An unapplied resolution should be reported rather than recorded.');
        } catch (SyncConflictResolutionException $exception) {
            $this->assertSame(SyncConflictResolutionException::REASON_NOT_APPLIED, $exception->reason);
            $this->assertStringContainsString('The referenced Field Report is missing.', $exception->getMessage());
        }

        $conflict->refresh();

        $this->assertTrue($conflict->isOpen());
        $this->assertNull($conflict->resolution);
        $this->assertNull($conflict->reviewed_by_user_id);
        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    /**
     * The applier learns that the disagreement it raised has been reviewed, so
     * it writes the accepted version instead of raising the same conflict again.
     */
    public function test_an_accepted_remote_version_is_applied_over_the_applier_conflict_signal(): void
    {
        $applier = $this->registerApplier();
        $applier->conflictWith = new SyncConflictException(
            reason: 'Local and remote Field Report state disagree.',
            localValue: ['status' => 'open'],
            remoteValue: ['status' => 'closed'],
        );

        [$conflict, $operation] = $this->conflict();

        $this->resolver->acceptOnsite($conflict, $this->reviewer);

        $this->assertSame(1, $applier->applied);
        $this->assertTrue($applier->seen[0]->resolvedConflict);
        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->fresh()->status);
        $this->assertDatabaseCount('sync_conflicts', 1);
    }

    /**
     * An applier that ignores the reviewed flag must not queue a second row for
     * a disagreement a human already decided.
     */
    public function test_an_applier_that_reraises_a_conflict_fails_instead_of_queueing_a_second_conflict(): void
    {
        $applier = $this->registerApplier();
        $applier->conflictWith = new SyncConflictException(reason: 'Still disagrees.');
        $applier->honourResolution = false;

        [$conflict, $operation] = $this->conflict();

        $this->expectException(SyncConflictResolutionException::class);

        try {
            $this->resolver->acceptOnsite($conflict, $this->reviewer);
        } finally {
            $this->assertDatabaseCount('sync_conflicts', 1);
            $this->assertSame(NodeOperation::STATUS_FAILED, $operation->fresh()->status);
            $this->assertTrue($conflict->fresh()->isOpen());
        }
    }

    /**
     * Technical spec 10.3: active event window plus event-scoped records default
     * to accept on-site.
     */
    public function test_an_event_scoped_conflict_in_an_active_window_defaults_to_accept_on_site(): void
    {
        $event = Event::factory()->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        [$conflict] = $this->conflict(['event_id' => (string) $event->getKey()]);

        $this->assertSame(
            SyncConflict::RESOLUTION_ACCEPT_ONSITE,
            $this->resolver->defaultResolutionFor($conflict),
        );
    }

    public function test_a_global_conflict_defaults_to_accept_central(): void
    {
        [$conflict] = $this->conflict();

        $this->assertSame(
            SyncConflict::RESOLUTION_ACCEPT_CENTRAL,
            $this->resolver->defaultResolutionFor($conflict),
        );
    }

    public function test_an_event_scoped_conflict_outside_the_window_defaults_to_accept_central(): void
    {
        $event = Event::factory()->create([
            'active_event_window_starts_at' => now()->addWeek(),
            'active_event_window_ends_at' => now()->addWeeks(2),
        ]);

        [$conflict] = $this->conflict(['event_id' => (string) $event->getKey()]);

        $this->assertSame(
            SyncConflict::RESOLUTION_ACCEPT_CENTRAL,
            $this->resolver->defaultResolutionFor($conflict),
        );
    }

    /**
     * Unresolved conflicts must not block unrelated sync (technical spec 10.3),
     * and resolving one must not settle another.
     */
    public function test_resolving_one_conflict_leaves_other_conflicts_open(): void
    {
        $this->registerApplier();
        [$first] = $this->conflict();
        [$second] = $this->conflict();

        $this->resolver->acceptOnsite($first, $this->reviewer);

        $this->assertTrue($second->fresh()->isOpen());
        $this->assertNull($second->fresh()->resolution);
    }

    /**
     * @param  array<string, mixed>  $operationOverrides
     * @return array{SyncConflict, NodeOperation}
     */
    private function conflict(array $operationOverrides = []): array
    {
        $operation = NodeOperation::factory()->conflicted()->create([
            'origin_node_id' => $this->remoteNode->getKey(),
            'entity_type' => 'field_report',
            'failure_reason' => 'Local and remote Field Report state disagree.',
            ...$operationOverrides,
        ]);

        $conflict = SyncConflict::factory()->forOperation($operation)->create([
            'local_value_json' => ['status' => 'open'],
            'remote_value_json' => ['status' => 'closed'],
            'reason' => 'Local and remote Field Report state disagree.',
        ]);

        return [$conflict, $operation];
    }

    private function registerApplier(): ResolvingNodeOperationApplier
    {
        $applier = new ResolvingNodeOperationApplier;

        app(NodeOperationApplierRegistry::class)->register($applier);

        return $applier;
    }
}

/**
 * A test applier that raises a conflict until a reviewer has decided, which is
 * the contract {@see NodeOperationApplier} describes, and can be told to ignore
 * that decision so the receiver's handling of a misbehaving applier is
 * observable.
 */
class ResolvingNodeOperationApplier implements NodeOperationApplier
{
    public int $applied = 0;

    public ?string $failWith = null;

    public ?SyncConflictException $conflictWith = null;

    public bool $honourResolution = true;

    /** @var list<SignedNodeOperation> */
    public array $seen = [];

    public function supports(SignedNodeOperation $operation): bool
    {
        return $operation->entityType === 'field_report';
    }

    public function apply(SignedNodeOperation $operation): void
    {
        $this->seen[] = $operation;

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $reviewed = $operation->resolvedConflict && $this->honourResolution;

        if (! $reviewed && $this->conflictWith instanceof SyncConflictException) {
            throw $this->conflictWith;
        }

        $this->applied++;
    }
}
