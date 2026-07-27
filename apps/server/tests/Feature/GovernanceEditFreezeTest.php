<?php

namespace Tests\Feature;

use App\Jobs\BumpPublishedDocumentFragmentRevisions;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\DocumentVersionSnapshot;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Documents\DocumentAdminService;
use App\Services\Documents\DocumentFragmentAdminService;
use App\Services\Node\EventAuthorityException;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\NodeOperationEnvelope;
use App\Services\Node\NodeOperationReceiver;
use App\Services\Node\NodeOperationSigner;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\SignedNodeOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M12.7 policy edit active-event block: policy/procedure document edits and
 * fragment edits are blocked during the active event window, so a fragment
 * change cannot silently bump the version of a published document mid-event
 * (technical spec 10.2, 21.10).
 *
 * The freeze is a different rule from M12.6 event authority, and the difference
 * is what most of these tests are about. Authority moves event-scoped writes to
 * the on-site primary node; the freeze permits the edit nowhere, including on
 * the node that holds authority over everything else. It is asked of the
 * organization that owns the content, because governance content is scoped to an
 * organization, department, or team and never carries an event.
 *
 * Governance content is created before the window opens throughout, the way
 * central prepares it, so what each test observes is the edit and not the setup.
 */
class GovernanceEditFreezeTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW_STARTS_AT = '2027-08-30 18:00:00';

    private const WINDOW_ENDS_AT = '2027-09-07 18:00:00';

    private const BEFORE_WINDOW = '2027-08-01 12:00:00';

    private const DURING_WINDOW = '2027-09-02 12:00:00';

    private const AFTER_WINDOW = '2027-09-20 12:00:00';

    private Organization $organization;

    private Event $event;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->actor = User::factory()->create();
        $this->event = Event::factory()->for($this->organization)->create([
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

    // The freeze

    public function test_governance_content_is_editable_while_central_prepares_the_event(): void
    {
        $policy = $this->policy();

        Carbon::setTestNow(self::BEFORE_WINDOW);

        app(DocumentAdminService::class)->save($policy, [
            ...$this->policyAttributes($policy),
            'markdown_source' => '# Conduct'."\n\nPrepared before the event.",
        ], $this->actor);

        $this->assertSame('# Conduct'."\n\nPrepared before the event.", $policy->refresh()->markdown_source);
    }

    public function test_a_policy_document_edit_is_refused_during_the_active_window(): void
    {
        $policy = $this->policy();

        Carbon::setTestNow(self::DURING_WINDOW);

        $refusal = $this->assertRefusesWrite(fn () => app(DocumentAdminService::class)->save($policy, [
            ...$this->policyAttributes($policy),
            'markdown_source' => '# Conduct'."\n\nChanged mid-event.",
        ], $this->actor));

        $this->assertSame(
            EventAuthorityException::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT,
            $refusal->reason,
        );
        $this->assertSame((string) $this->event->getKey(), $refusal->eventId);
        $this->assertNull($refusal->authoritativeNodeId);
        $this->assertStringContainsString('Juplaya 2027', $refusal->getMessage());
        $this->assertStringContainsString('policy document', $refusal->getMessage());
    }

    public function test_a_procedure_document_edit_is_refused_during_the_active_window(): void
    {
        $procedure = ProcedureDocument::factory()->for($this->organization)->create([
            'scope_type' => ProcedureDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->getKey(),
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $refusal = $this->assertRefusesWrite(
            fn () => $procedure->forceFill(['markdown_source' => 'Changed mid-event.'])->save(),
        );

        $this->assertSame(
            EventAuthorityException::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT,
            $refusal->reason,
        );
    }

    public function test_a_fragment_edit_is_refused_during_the_active_window(): void
    {
        $fragment = $this->fragment();

        Carbon::setTestNow(self::DURING_WINDOW);

        $refusal = $this->assertRefusesWrite(fn () => app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'Changed mid-event.'],
            $this->actor,
        ));

        $this->assertSame(
            EventAuthorityException::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT,
            $refusal->reason,
        );
        $this->assertStringContainsString('document fragment', $refusal->getMessage());
        $this->assertSame(1, $fragment->refresh()->version);
    }

    public function test_creating_governance_content_is_refused_during_the_active_window(): void
    {
        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertRefusesWrite(fn () => PolicyDocument::factory()->for($this->organization)->create([
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->getKey(),
        ]));

        $this->assertDatabaseCount('policy_documents', 0);
    }

    public function test_deleting_governance_content_is_refused_during_the_active_window(): void
    {
        $fragment = $this->fragment();

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertRefusesWrite(fn () => $fragment->delete());

        $this->assertModelExists($fragment);
    }

    public function test_governance_content_is_editable_again_after_the_window_closes(): void
    {
        $fragment = $this->fragment();

        Carbon::setTestNow(self::AFTER_WINDOW);

        app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'Corrected after the event.'],
            $this->actor,
        );

        $this->assertSame('Corrected after the event.', $fragment->refresh()->markdown_source);
    }

    /**
     * The distinguishing rule of M12.7. Under event authority the on-site
     * primary node is the one node that may write; under the freeze it may not
     * write governance content either, because a mid-event version bump is
     * wrong wherever it is made.
     */
    public function test_the_freeze_also_applies_on_the_onsite_node_that_holds_event_authority(): void
    {
        Node::factory()->onsite()->signing()->create([
            'node_name' => 'juplaya.2027.onsite',
            'event_id' => $this->event->getKey(),
        ]);

        $policy = $this->policy();

        Carbon::setTestNow(self::DURING_WINDOW);

        $refusal = $this->assertRefusesWrite(
            fn () => $policy->forceFill(['markdown_source' => 'Changed on-site.'])->save(),
        );

        $this->assertSame(
            EventAuthorityException::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT,
            $refusal->reason,
        );
        $this->assertStringContainsString('on any node', $refusal->getMessage());
    }

    // Which window applies

    public function test_another_organizations_active_event_does_not_freeze_this_organizations_content(): void
    {
        $fragment = $this->fragment();

        Event::factory()->create([
            'name' => 'Somewhere Else 2027',
            'active_event_window_starts_at' => self::WINDOW_STARTS_AT,
            'active_event_window_ends_at' => self::WINDOW_ENDS_AT,
        ]);

        $this->event->forceFill(['active_event_window_starts_at' => null])->save();

        Carbon::setTestNow(self::DURING_WINDOW);

        app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'Unaffected by another organization.'],
            $this->actor,
        );

        $this->assertSame('Unaffected by another organization.', $fragment->refresh()->markdown_source);
    }

    public function test_an_archived_events_stale_window_does_not_freeze_governance_content(): void
    {
        $fragment = $this->fragment();

        $this->event->forceFill(['archived_at' => now()])->save();

        Carbon::setTestNow(self::DURING_WINDOW);

        app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'The event was archived.'],
            $this->actor,
        );

        $this->assertSame('The event was archived.', $fragment->refresh()->markdown_source);
    }

    public function test_an_organization_with_no_scheduled_window_never_freezes(): void
    {
        $fragment = $this->fragment();

        $this->event->forceFill([
            'active_event_window_starts_at' => null,
            'active_event_window_ends_at' => null,
        ])->save();

        Carbon::setTestNow(self::DURING_WINDOW);

        app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'No window was ever declared.'],
            $this->actor,
        );

        $this->assertSame('No window was ever declared.', $fragment->refresh()->markdown_source);
    }

    /**
     * A window with no recorded end stays active, so it keeps governance content
     * frozen rather than thawing it because an end time was never entered.
     */
    public function test_an_open_ended_window_keeps_governance_content_frozen(): void
    {
        $fragment = $this->fragment();

        $this->event->forceFill(['active_event_window_ends_at' => null])->save();

        Carbon::setTestNow(self::AFTER_WINDOW);

        $refusal = $this->assertRefusesWrite(fn () => app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'The window was never closed.'],
            $this->actor,
        ));

        $this->assertSame(
            EventAuthorityException::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT,
            $refusal->reason,
        );
    }

    /**
     * Any of the organization's events being in its window freezes the
     * organization's content, not only the first event the query happens to see.
     */
    public function test_a_second_event_in_its_window_freezes_the_organizations_content(): void
    {
        $fragment = $this->fragment();

        $this->event->forceFill([
            'active_event_window_starts_at' => '2027-06-01 12:00:00',
            'active_event_window_ends_at' => '2027-06-08 12:00:00',
        ])->save();

        Event::factory()->for($this->organization)->create([
            'name' => 'Juplaya 2027 Build Week',
            'active_event_window_starts_at' => self::WINDOW_STARTS_AT,
            'active_event_window_ends_at' => self::WINDOW_ENDS_AT,
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $refusal = $this->assertRefusesWrite(fn () => app(DocumentFragmentAdminService::class)->save(
            $fragment,
            ['markdown_source' => 'Changed mid-event.'],
            $this->actor,
        ));

        $this->assertStringContainsString('Juplaya 2027 Build Week', $refusal->getMessage());
    }

    // Version bumps

    /**
     * The reason the specification gives for freezing fragments. A queued bump
     * from a fragment edited just before the window is refused rather than
     * raising the version of a published document mid-event.
     */
    public function test_a_queued_fragment_revision_bump_is_refused_during_the_active_window(): void
    {
        $fragment = $this->fragment();
        $policy = $this->policy(['state' => PolicyDocument::STATE_PUBLISHED, 'published_at' => now()]);

        DocumentFragmentReference::query()->create([
            'document_type' => DocumentFragmentReference::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->getKey(),
            'fragment_id' => $fragment->getKey(),
            'token' => '{{fragment:'.$fragment->slug.'}}',
            'fragment_version_at_last_edit' => $fragment->version,
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertRefusesWrite(
            fn () => (new BumpPublishedDocumentFragmentRevisions((string) $fragment->getKey(), 2))->handle(),
        );

        $this->assertSame(0, $policy->refresh()->fragment_revision);
        $this->assertDatabaseCount('document_fragment_version_bumps', 0);
    }

    /**
     * `Model::increment()` fires `updating` and never `saving`, so the guard
     * listens to both. A revision raised that way would otherwise be the one
     * silent mid-event version bump that got through.
     */
    public function test_incrementing_a_document_revision_directly_is_refused_during_the_active_window(): void
    {
        $policy = $this->policy(['state' => PolicyDocument::STATE_PUBLISHED, 'published_at' => now()]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->assertRefusesWrite(fn () => $policy->increment('fragment_revision'));

        $this->assertSame(0, $policy->refresh()->fragment_revision);
    }

    // What stays writable

    /**
     * Acknowledgments collected on-site during signup or training sync back to
     * central (technical spec 10.2), so neither the acknowledgment nor the
     * version snapshot it preserves may be frozen.
     */
    public function test_acknowledgments_and_their_version_snapshots_stay_writable_during_the_window(): void
    {
        $policy = $this->policy(['state' => PolicyDocument::STATE_PUBLISHED, 'published_at' => now()]);

        Carbon::setTestNow(self::DURING_WINDOW);

        DocumentVersionSnapshot::factory()->forDocument($policy)->create();
        DocumentAcknowledgment::factory()->forDocument($policy)->create(['user_id' => $this->actor->getKey()]);

        $this->assertDatabaseCount('document_version_snapshots', 1);
        $this->assertDatabaseCount('document_acknowledgments', 1);
    }

    /**
     * A requirement is the live signup gate rather than document content, and
     * changing one bumps no version, so an organizer can still lift a
     * requirement that is blocking signups while the event runs.
     */
    public function test_acknowledgment_requirements_stay_writable_during_the_window(): void
    {
        $policy = $this->policy(['state' => PolicyDocument::STATE_PUBLISHED, 'published_at' => now()]);

        $requirement = DocumentAcknowledgmentRequirement::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->getKey(),
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->getKey(),
            'active' => true,
        ]);

        Carbon::setTestNow(self::DURING_WINDOW);

        $requirement->forceFill(['active' => false])->save();

        $this->assertFalse($requirement->refresh()->active);
    }

    // The sync path

    /**
     * No node can create a document operation while the window is open, so one
     * arriving mid-window carries an edit made before it opened. Applying it is
     * how content central prepared reaches on-site after a lost connection.
     */
    public function test_an_applied_operation_writes_governance_content_during_the_window(): void
    {
        $fragment = $this->fragment();

        $keys = app(NodeKeyPairGenerator::class)->generate();
        Node::factory()->central()->signing()->create(['node_name' => 'juplaya.central']);
        $peer = Node::factory()->onsite()->remote()->create([
            'node_name' => 'juplaya.2027.onsite',
            'public_key' => $keys['public_key'],
        ]);

        app(NodeOperationApplierRegistry::class)->register(
            new GovernanceTestApplier($fragment),
        );

        Carbon::setTestNow(self::DURING_WINDOW);

        $receipt = app(NodeOperationReceiver::class)->receiveAndApply(
            $this->signedEnvelope($peer, $keys),
        );

        $this->assertTrue($receipt->wasApplied());
        $this->assertSame('Prepared on central before the window.', $fragment->refresh()->markdown_source);
    }

    // The product surface

    public function test_the_product_document_api_refuses_a_mid_event_edit_with_a_conflict(): void
    {
        $policy = $this->policy();
        $organizer = $this->organizerUser();

        Carbon::setTestNow(self::DURING_WINDOW);

        $this->actingAs($organizer)
            ->postJson('/api/commands/update-policy-document', [
                'document_id' => $policy->getKey(),
                'organization_id' => $this->organization->getKey(),
                'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                'scope_id' => $this->organization->getKey(),
                'title' => $policy->title,
                'slug' => $policy->slug,
                'markdown_source' => 'Changed mid-event.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', EventAuthorityException::REASON_GOVERNANCE_FROZEN_DURING_ACTIVE_EVENT)
            ->assertJsonPath('event_id', (string) $this->event->getKey())
            ->assertJsonPath('authoritative_node_id', null);
    }

    public function test_the_product_document_api_accepts_the_same_edit_before_the_window_opens(): void
    {
        $policy = $this->policy();
        $organizer = $this->organizerUser();

        Carbon::setTestNow(self::BEFORE_WINDOW);

        $this->actingAs($organizer)
            ->postJson('/api/commands/update-policy-document', [
                'document_id' => $policy->getKey(),
                'organization_id' => $this->organization->getKey(),
                'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
                'scope_id' => $this->organization->getKey(),
                'title' => $policy->title,
                'slug' => $policy->slug,
                'markdown_source' => 'Prepared before the event.',
            ])
            ->assertOk();

        $this->assertSame('Prepared before the event.', $policy->refresh()->markdown_source);
    }

    // Fixtures

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function policy(array $attributes = []): PolicyDocument
    {
        return PolicyDocument::factory()->for($this->organization)->create([
            'title' => 'Volunteer Conduct',
            'slug' => 'volunteer-conduct',
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->getKey(),
            ...$attributes,
        ]);
    }

    private function fragment(): DocumentFragment
    {
        return DocumentFragment::factory()->for($this->organization)->create([
            'name' => 'Conduct Baseline',
            'slug' => 'conduct-baseline',
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->getKey(),
            'markdown_source' => 'Be excellent to each other.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function policyAttributes(PolicyDocument $policy): array
    {
        return $policy->only([
            'organization_id',
            'scope_type',
            'scope_id',
            'title',
            'slug',
            'event_info_section',
            'markdown_source',
            'state',
        ]);
    }

    private function organizerUser(): User
    {
        $department = Department::factory()->for($this->organization)->create([
            'name' => 'Organizers',
            'code' => 'ORG',
        ]);
        $this->organization->forceFill(['organizers_department_id' => $department->getKey()])->save();

        $team = Team::factory()->for($department)->create(['is_default' => true]);
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
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', 'organizer')->firstOrFail()->getKey(),
        ]);

        return $user;
    }

    /**
     * @param  array{public_key: string, private_key: string}  $keys
     */
    private function signedEnvelope(Node $originNode, array $keys): NodeOperationEnvelope
    {
        $signer = app(NodeOperationSigner::class);

        $operation = new NodeOperation;
        $operation->forceFill([
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => (string) $originNode->getKey(),
            'target_node_id' => null,
            'actor_user_id' => (string) $this->actor->getKey(),
            'actor_device_id' => null,
            'operation_type' => 'upsert',
            'entity_type' => 'document_fragment',
            'entity_id' => (string) Str::uuid(),
            'event_id' => null,
            'created_at' => now(),
            'payload_json' => null,
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

    private function assertRefusesWrite(callable $write): EventAuthorityException
    {
        try {
            $write();
        } catch (EventAuthorityException $refusal) {
            return $refusal;
        }

        $this->fail('The governance write was not refused.');
    }
}

/**
 * An applier that writes governance content, so the apply path can be observed
 * writing what the receiving node would refuse to write itself.
 */
class GovernanceTestApplier implements NodeOperationApplier
{
    public function __construct(private readonly DocumentFragment $fragment) {}

    public function supports(SignedNodeOperation $operation): bool
    {
        return $operation->entityType === 'document_fragment';
    }

    public function apply(SignedNodeOperation $operation): void
    {
        $this->fragment->forceFill([
            'markdown_source' => 'Prepared on central before the window.',
        ])->save();
    }
}
