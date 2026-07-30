<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentListPreset;
use App\Models\IncidentTimelineEntry;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Node\EventAuthority;
use App\Services\Node\EventAuthorityException;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\NodeOperationEnvelope;
use App\Services\Node\NodeOperationReceiver;
use App\Services\Node\NodeOperationRejectedException;
use App\Services\Node\NodeOperationSigner;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\SignedNodeOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M12.6 active event authority: during the active event window the on-site
 * primary node is authoritative for event-scoped records, central is read-only
 * for that event except for data arriving from the on-site primary node, and
 * edits not from the on-site primary node are refused (technical spec 10.2;
 * data/API 7.4).
 *
 * The rule is exercised against both write paths, because both are ways to
 * change the same records: a user editing on this node, and an operation
 * arriving from a peer. The peer is simulated the way a real one behaves — its
 * own keypair, signing its own canonical payload, known here only as a paired
 * peer `nodes` record.
 */
class EventAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW_STARTS_AT = '2027-08-30 18:00:00';

    private const WINDOW_ENDS_AT = '2027-09-07 18:00:00';

    private const DURING_WINDOW = '2027-09-02 12:00:00';

    private EventAuthority $authority;

    private Event $event;

    private User $actor;

    /** @var array{public_key: string, private_key: string}|null */
    private ?array $onsiteKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authority = app(EventAuthority::class);
        $this->actor = User::factory()->create();
        $this->event = Event::factory()->create([
            'name' => 'Juplaya 2027',
            'active_event_window_starts_at' => self::WINDOW_STARTS_AT,
            'active_event_window_ends_at' => self::WINDOW_ENDS_AT,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // The authority lifecycle

    public function test_an_event_is_in_preparation_before_its_active_window(): void
    {
        Carbon::setTestNow('2027-08-01 12:00:00');

        $this->assertSame(EventAuthority::PHASE_PREPARATION, $this->authority->phaseFor($this->event));
        $this->assertFalse($this->authority->isActive($this->event));
    }

    public function test_an_event_is_active_inside_its_window(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertSame(EventAuthority::PHASE_ACTIVE, $this->authority->phaseFor($this->event));
        $this->assertTrue($this->authority->isActive($this->event));
    }

    public function test_an_event_is_closed_after_its_window(): void
    {
        Carbon::setTestNow('2027-09-20 12:00:00');

        $this->assertSame(EventAuthority::PHASE_CLOSED, $this->authority->phaseFor($this->event));
        $this->assertFalse($this->authority->isActive($this->event));
    }

    /**
     * Authority is a handover, and nothing records when an unscheduled window
     * would hand over.
     */
    public function test_an_event_without_a_window_start_never_becomes_active(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->event->forceFill(['active_event_window_starts_at' => null])->save();

        $this->assertSame(EventAuthority::PHASE_PREPARATION, $this->authority->phaseFor($this->event));
    }

    /**
     * The window closes when someone records that it closed, not because an end
     * time was never entered.
     */
    public function test_a_window_with_no_end_stays_active(): void
    {
        Carbon::setTestNow('2028-01-01 12:00:00');

        $this->event->forceFill(['active_event_window_ends_at' => null])->save();

        $this->assertSame(EventAuthority::PHASE_ACTIVE, $this->authority->phaseFor($this->event));
    }

    public function test_an_archived_event_is_never_active(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->event->forceFill(['archived_at' => now()])->save();

        $this->assertSame(EventAuthority::PHASE_PREPARATION, $this->authority->phaseFor($this->event));
    }

    // Who holds authority

    public function test_the_paired_onsite_node_holds_authority_during_the_window(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $onsite = $this->onsitePeer();

        $this->assertTrue($onsite->is($this->authority->authoritativeNodeFor($this->event)));
    }

    public function test_no_node_holds_exclusive_authority_outside_the_window(): void
    {
        Carbon::setTestNow('2027-08-01 12:00:00');

        $this->onsitePeer();

        $this->assertNull($this->authority->authoritativeNodeFor($this->event));
    }

    public function test_an_onsite_node_serving_another_event_does_not_hold_authority(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->onsitePeer(['event_id' => Event::factory()->create()->getKey()]);

        $this->assertNull($this->authority->authoritativeNodeFor($this->event));
    }

    public function test_the_node_named_for_this_event_is_preferred(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->onsitePeer(['node_name' => 'unnamed.onsite']);
        $named = $this->onsitePeer([
            'node_name' => 'juplaya.2027.onsite',
            'event_id' => $this->event->getKey(),
        ]);

        $this->assertTrue($named->is($this->authority->authoritativeNodeFor($this->event)));
    }

    public function test_an_unpaired_or_revoked_onsite_peer_does_not_hold_authority(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->onsitePeer(['node_name' => 'never-paired.onsite', 'paired_at' => null]);
        $this->onsitePeer(['node_name' => 'revoked.onsite', 'revoked_at' => now()]);

        $this->assertNull($this->authority->authoritativeNodeFor($this->event));
    }

    // Local writes

    /**
     * The behavior the task exists for: central cannot edit this event's
     * records while the on-site node is running it.
     */
    public function test_central_refuses_event_scoped_writes_during_the_active_window(): void
    {
        $this->asCentralInstall();
        $onsite = $this->onsitePeer();

        Carbon::setTestNow(self::DURING_WINDOW);

        $refusal = $this->assertRefusesLocalWrite(fn () => $this->incident());

        $this->assertSame(EventAuthorityException::REASON_READ_ONLY_DURING_ACTIVE_EVENT, $refusal->reason);
        $this->assertSame((string) $this->event->getKey(), $refusal->eventId);
        $this->assertSame((string) $onsite->getKey(), $refusal->authoritativeNodeId);
        $this->assertStringContainsString('Juplaya 2027', $refusal->getMessage());
        $this->assertStringContainsString((string) $onsite->node_name, $refusal->getMessage());
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_central_writes_event_records_before_the_window_starts(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        Carbon::setTestNow('2027-08-01 12:00:00');

        $this->incident();

        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_central_writes_event_records_after_the_window_closes(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        Carbon::setTestNow('2027-09-20 12:00:00');

        $this->incident();

        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_the_onsite_node_writes_event_records_during_the_window(): void
    {
        Node::factory()->onsite()->create(['node_name' => 'juplaya.2027.onsite']);

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->incident();

        $this->assertDatabaseCount('incidents', 1);
    }

    /**
     * Authority is a handover to another node. With no node to hand it to,
     * refusing would leave the event unmanageable on the only node running it.
     */
    public function test_writes_are_allowed_during_the_window_when_no_onsite_node_is_known(): void
    {
        $this->asCentralInstall();

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->incident();

        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_a_record_scoped_through_its_parent_is_refused(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        $incident = $this->incident();

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertRefusesLocalWrite(fn () => IncidentTimelineEntry::factory()->create([
            'incident_id' => $incident->getKey(),
            'actor_user_id' => $this->actor->getKey(),
        ]));

        $this->assertDatabaseCount('incident_timeline_entries', 0);
    }

    /**
     * Permission changes happen only on the on-site primary node during the
     * active event window (technical spec 10.2). Event-scoped team grants are
     * how Meridian records them.
     */
    public function test_central_refuses_event_scoped_permission_changes_during_the_window(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        $team = Team::factory()->create();
        $role = PermissionRole::query()->firstOrFail();

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertRefusesLocalWrite(fn () => TeamGrant::factory()->create([
            'team_id' => $team->getKey(),
            'event_id' => $this->event->getKey(),
            'permission_role_id' => $role->getKey(),
        ]));

        $this->assertDatabaseCount('team_grants', 0);
    }

    /**
     * A read-only node still has to be able to record what it received, what it
     * refused, and that the window it is read-only for has changed.
     */
    public function test_a_read_only_node_still_records_its_own_bookkeeping(): void
    {
        $central = $this->asCentralInstall();
        $this->onsitePeer();

        Carbon::setTestNow(self::DURING_WINDOW);

        NodeOperation::factory()->create([
            'event_id' => $this->event->getKey(),
            'origin_node_id' => $central->getKey(),
        ]);
        AuditEvent::factory()->create(['event_id' => $this->event->getKey()]);
        IncidentListPreset::factory()->create([
            'event_id' => $this->event->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);

        $this->event->forceFill(['active_event_window_ends_at' => self::DURING_WINDOW])->save();

        $this->assertDatabaseCount('node_operations', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('incident_list_presets', 1);
        $this->assertSame(
            Carbon::parse(self::DURING_WINDOW)->toDateTimeString(),
            $this->event->fresh()->active_event_window_ends_at->toDateTimeString(),
        );
    }

    public function test_records_for_another_event_are_untouched_by_this_events_window(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer(['event_id' => $this->event->getKey()]);

        $other = Event::factory()->create([
            'active_event_window_starts_at' => null,
            'active_event_window_ends_at' => null,
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        Incident::factory()->forEvent($other)->create(['created_by_user_id' => $this->actor->getKey()]);

        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_an_event_scoped_command_is_refused_with_a_conflict(): void
    {
        $event = $this->eventWithIncidentCommandDepartment();
        $user = $this->icLeadFor($event);

        $this->asCentralInstall();
        $onsite = $this->onsitePeer(['event_id' => $event->getKey()]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->actingAsClient($user)
            ->postJson('/api/commands/create-incident', [
                'event_id' => $event->getKey(),
                'title' => 'Medical assist at Gate A',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', EventAuthorityException::REASON_READ_ONLY_DURING_ACTIVE_EVENT)
            ->assertJsonPath('event_id', (string) $event->getKey())
            ->assertJsonPath('authoritative_node_id', (string) $onsite->getKey());

        $this->assertDatabaseCount('incidents', 0);
    }

    // Operations arriving from a peer

    public function test_central_accepts_event_scoped_operations_from_the_onsite_node(): void
    {
        $this->asCentralInstall();
        $onsite = $this->onsitePeer();

        Carbon::setTestNow(self::DURING_WINDOW);

        $receipt = app(NodeOperationReceiver::class)->receive(
            $this->signedEnvelope($onsite, ['event_id' => (string) $this->event->getKey()]),
        );

        $this->assertTrue($receipt->stored);
        $this->assertDatabaseCount('node_operations', 1);
    }

    /**
     * Data arriving from the on-site primary node is the documented exception
     * to a read-only node, so applying has to write records the same node would
     * refuse to write itself.
     */
    public function test_an_applied_operation_writes_event_records_on_a_read_only_node(): void
    {
        $this->asCentralInstall();
        $onsite = $this->onsitePeer();

        app(NodeOperationApplierRegistry::class)->register(
            new EventScopedTestApplier($this->event, $this->actor),
        );

        Carbon::setTestNow(self::DURING_WINDOW);

        $receipt = app(NodeOperationReceiver::class)->receiveAndApply(
            $this->signedEnvelope($onsite, ['event_id' => (string) $this->event->getKey()]),
        );

        $this->assertTrue($receipt->wasApplied());
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_central_refuses_event_scoped_operations_from_another_node(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        $stranger = $this->onsitePeer([
            'node_name' => 'elsewhere.onsite',
            'event_id' => Event::factory()->create()->getKey(),
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $envelope = $this->signedEnvelope($stranger, ['event_id' => (string) $this->event->getKey()]);
        $rejection = $this->assertRefusesOperation($envelope);

        $this->assertSame(NodeOperationRejectedException::REASON_EVENT_AUTHORITY, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
        $this->assertRefusalAudited($envelope->uuid(), NodeOperationRejectedException::REASON_EVENT_AUTHORITY);
    }

    /**
     * The same rule read from the other side: on-site holds authority, so an
     * event-scoped edit arriving from central during the window is an edit not
     * from the on-site primary node.
     */
    public function test_the_onsite_node_refuses_event_scoped_operations_from_central(): void
    {
        $keys = app(NodeKeyPairGenerator::class)->generate();

        Node::factory()->onsite()->signing()->create(['node_name' => 'juplaya.2027.onsite']);
        $central = Node::factory()->central()->remote()->create([
            'node_name' => 'juplaya.central',
            'public_key' => $keys['public_key'],
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $rejection = $this->assertRefusesOperation(
            $this->signedEnvelope($central, ['event_id' => (string) $this->event->getKey()], $keys),
        );

        $this->assertSame(NodeOperationRejectedException::REASON_EVENT_AUTHORITY, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_that_names_no_event_is_not_affected(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        $keys = app(NodeKeyPairGenerator::class)->generate();
        $stranger = $this->onsitePeer([
            'node_name' => 'elsewhere.onsite',
            'event_id' => Event::factory()->create()->getKey(),
            'public_key' => $keys['public_key'],
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $receipt = app(NodeOperationReceiver::class)->receive(
            $this->signedEnvelope($stranger, ['event_id' => null], $keys),
        );

        $this->assertTrue($receipt->stored);
    }

    public function test_operations_are_not_refused_outside_the_active_window(): void
    {
        $this->asCentralInstall();
        $this->onsitePeer();

        $keys = app(NodeKeyPairGenerator::class)->generate();
        $stranger = $this->onsitePeer([
            'node_name' => 'elsewhere.onsite',
            'event_id' => Event::factory()->create()->getKey(),
            'public_key' => $keys['public_key'],
        ]);

        Carbon::setTestNow('2027-09-20 12:00:00');

        $receipt = app(NodeOperationReceiver::class)->receive(
            $this->signedEnvelope($stranger, ['event_id' => (string) $this->event->getKey()], $keys),
        );

        $this->assertTrue($receipt->stored);
    }

    // Fixtures

    /**
     * This install is central and holds its own signing keys.
     */
    private function asCentralInstall(): Node
    {
        return Node::factory()->central()->signing()->create(['node_name' => 'juplaya.central']);
    }

    /**
     * A paired on-site peer node record, as central knows it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function onsitePeer(array $attributes = []): Node
    {
        $this->onsiteKeys ??= app(NodeKeyPairGenerator::class)->generate();

        return Node::factory()->onsite()->remote()->create([
            'node_name' => 'juplaya.2027.onsite',
            'public_key' => $this->onsiteKeys['public_key'],
            ...$attributes,
        ]);
    }

    private function incident(): Incident
    {
        return Incident::factory()
            ->forEvent($this->event)
            ->create(['created_by_user_id' => $this->actor->getKey()]);
    }

    /**
     * An envelope signed the way the origin node signs it: over the canonical
     * payload, with that node's own private key.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array{public_key: string, private_key: string}|null  $keys
     */
    private function signedEnvelope(Node $originNode, array $overrides = [], ?array $keys = null): NodeOperationEnvelope
    {
        $keys ??= $this->onsiteKeys;

        $signer = app(NodeOperationSigner::class);

        $operation = new NodeOperation;
        $operation->forceFill([
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => (string) $originNode->getKey(),
            'target_node_id' => null,
            'actor_user_id' => (string) $this->actor->getKey(),
            'actor_device_id' => null,
            'operation_type' => 'upsert',
            'entity_type' => 'incident',
            'entity_id' => (string) Str::uuid(),
            'event_id' => (string) $this->event->getKey(),
            'created_at' => now(),
            'payload_json' => null,
            ...$overrides,
        ]);

        $operation->forceFill([
            'hash' => $signer->hashFor($operation),
            'signature' => app(NodeSignatureAlgorithm::class)->sign(
                $signer->canonicalPayload($operation),
                $keys['private_key'],
            ),
        ]);

        return NodeOperationEnvelope::fromOperation($operation);
    }

    private function assertRefusesLocalWrite(callable $write): EventAuthorityException
    {
        try {
            $write();
        } catch (EventAuthorityException $refusal) {
            return $refusal;
        }

        $this->fail('The event-scoped write was not refused.');
    }

    private function assertRefusesOperation(NodeOperationEnvelope $envelope): NodeOperationRejectedException
    {
        try {
            app(NodeOperationReceiver::class)->receive($envelope);
        } catch (NodeOperationRejectedException $rejection) {
            return $rejection;
        }

        $this->fail('The node operation was not refused.');
    }

    private function assertRefusalAudited(string $uuid, string $reasonCode): void
    {
        $audit = AuditEvent::query()
            ->where('action', NodeOperationReceiver::AUDIT_REJECTED)
            ->where('entity_id', $uuid)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit, 'The refusal was not audited.');
        $this->assertSame(AuditEvent::SOURCE_SYNC, $audit->source_context);
        $this->assertSame($reasonCode, $audit->after_json['reason_code']);
    }

    private function eventWithIncidentCommandDepartment(): Event
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        return Event::factory()->for($organization)->create([
            'name' => 'Juplaya 2027',
            'ic_department_id' => $department->getKey(),
            'starts_at' => Carbon::parse('2027-09-01 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
            'active_event_window_starts_at' => self::WINDOW_STARTS_AT,
            'active_event_window_ends_at' => self::WINDOW_ENDS_AT,
        ]);
    }

    private function icLeadFor(Event $event): User
    {
        $department = Department::query()->findOrFail($event->ic_department_id);
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->getKey());

        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->getKey(),
            'staff_id' => $staff->getKey(),
            'department_membership_id' => $membership->getKey(),
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->getKey(),
            'event_id' => $event->getKey(),
            'permission_role_id' => PermissionRole::query()->where('code', 'ic_lead')->firstOrFail()->getKey(),
        ]);

        return $user;
    }
}

/**
 * An applier that writes an event-scoped record, so the apply path can be
 * observed writing what the receiving node would refuse to write itself.
 */
class EventScopedTestApplier implements NodeOperationApplier
{
    public function __construct(
        private readonly Event $event,
        private readonly User $actor,
    ) {}

    public function supports(SignedNodeOperation $operation): bool
    {
        return $operation->entityType === 'incident';
    }

    public function apply(SignedNodeOperation $operation): void
    {
        Incident::factory()->forEvent($this->event)->create([
            'id' => $operation->entityId,
            'created_by_user_id' => $this->actor->getKey(),
        ]);
    }
}
