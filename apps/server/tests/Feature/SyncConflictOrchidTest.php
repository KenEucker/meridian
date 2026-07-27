<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\SignedNodeOperation;
use App\Services\Node\SyncConflictResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God-mode Orchid queue and resolver for sync conflicts (technical spec 10.3;
 * data/API 7.5, 14.2; UI contract `orchid.sync-conflicts`).
 */
class SyncConflictOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_god_mode_user_can_view_the_conflict_queue_grouped_by_entity_type(): void
    {
        $attendance = SyncConflict::factory()->forEntity('attendance_record')->create([
            'reason' => 'Attendance local and remote disagree.',
            'local_value_json' => ['status' => 'open'],
            'remote_value_json' => ['status' => 'no_show'],
        ]);
        $incident = SyncConflict::factory()->forEntity('incident')->create([
            'reason' => 'Incident title mismatch.',
        ]);

        $user = $this->godModeUser();

        $response = $this->actingAs($user)->get(route('platform.sync-conflicts'));

        $response->assertOk();
        $response->assertSee('Sync Conflicts');
        $response->assertSee('attendance_record');
        $response->assertSee('incident');
        $response->assertSee('Attendance local and remote disagree.');
        $response->assertSee('Incident title mismatch.');
        $response->assertSee('Open');
        $response->assertSee('Not resolved');

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertLessThan(
            strpos($body, 'incident'),
            strpos($body, 'attendance_record'),
            'Conflicts should be ordered by entity type so reviewers can work one class at a time.',
        );

        $this->assertNotNull($attendance->id);
        $this->assertNotNull($incident->id);
    }

    public function test_god_mode_user_can_review_local_and_remote_values_and_both_resolutions(): void
    {
        $conflict = SyncConflict::factory()->forEntity('attendance_record')->create([
            'reason' => 'Check-out times disagree.',
            'local_value_json' => ['checked_out_at' => '2026-07-27T17:00:00Z'],
            'remote_value_json' => ['checked_out_at' => '2026-07-27T18:00:00Z'],
        ]);

        $user = $this->godModeUser();

        $response = $this->actingAs($user)->get(route('platform.sync-conflicts.show', $conflict));

        $response->assertOk();
        $response->assertSee('Review Sync Conflict');
        $response->assertSee('attendance_record');
        $response->assertSee('Check-out times disagree.');
        $response->assertSee('Local value');
        $response->assertSee('Remote value');
        $response->assertSee('2026-07-27T17:00:00Z');
        $response->assertSee('2026-07-27T18:00:00Z');
        $response->assertSee('Back to queue');
        $response->assertSee('Accept on-site');
        $response->assertSee('Accept central');
        $response->assertSee('Recommended default');
    }

    /**
     * Technical spec 10.3: conflicts are not manually corrected inside the
     * resolver, so the two versions stay read-only.
     */
    public function test_the_review_screen_offers_no_way_to_edit_the_conflicting_values(): void
    {
        $conflict = SyncConflict::factory()->create();

        $response = $this->actingAs($this->godModeUser())
            ->get(route('platform.sync-conflicts.show', $conflict));

        $response->assertOk();
        $response->assertSee('Values are not edited here');

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertStringNotContainsString('name="conflict[local_value_json]"', $body);
        $this->assertStringNotContainsString('name="conflict[remote_value_json]"', $body);
    }

    public function test_a_resolved_conflict_shows_its_decision_and_offers_no_further_action(): void
    {
        $this->registerApplier();
        [$conflict] = $this->resolvableConflict();
        $reviewer = $this->godModeUser();

        app(SyncConflictResolver::class)->acceptOnsite($conflict, $reviewer);

        $response = $this->actingAs($reviewer)
            ->get(route('platform.sync-conflicts.show', $conflict->fresh()));

        $response->assertOk();
        $response->assertSee('Resolved');
        $response->assertSee('Accept on-site');
        $response->assertDontSee('>Accept central<', false);
    }

    public function test_god_mode_accept_on_site_action_applies_the_operation_and_audits_the_decision(): void
    {
        $this->registerApplier();
        [$conflict, $operation] = $this->resolvableConflict();
        $reviewer = $this->godModeUser();

        $this->screen('platform.sync-conflicts.show', ['conflict' => $conflict->id])
            ->actingAs($reviewer)
            ->withoutFollowingRedirects()
            ->method('acceptOnsite')
            ->assertRedirect(route('platform.sync-conflicts.show', $conflict));

        $conflict->refresh();

        $this->assertSame(SyncConflict::STATUS_RESOLVED, $conflict->status);
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_ONSITE, $conflict->resolution);
        $this->assertSame($reviewer->id, $conflict->reviewed_by_user_id);
        $this->assertNotNull($conflict->reviewed_at);
        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->fresh()->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => SyncConflictResolver::AUDIT_RESOLVED,
            'entity_id' => $conflict->id,
            'actor_user_id' => $reviewer->id,
        ]);
    }

    /**
     * The resolver refuses when the accepted version cannot be written, and the
     * screen reports that without recording a decision.
     */
    public function test_a_resolution_that_cannot_be_applied_leaves_the_conflict_open(): void
    {
        [$conflict, $operation] = $this->resolvableConflict();
        $reviewer = $this->godModeUser();

        $this->screen('platform.sync-conflicts.show', ['conflict' => $conflict->id])
            ->actingAs($reviewer)
            ->withoutFollowingRedirects()
            ->method('acceptOnsite')
            ->assertRedirect(route('platform.sync-conflicts.show', $conflict));

        $conflict->refresh();

        $this->assertTrue($conflict->isOpen());
        $this->assertNull($conflict->resolution);
        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_god_mode_accept_central_action_keeps_local_state_and_audits_the_decision(): void
    {
        [$conflict, $operation] = $this->resolvableConflict();
        $reviewer = $this->godModeUser();

        $this->screen('platform.sync-conflicts.show', ['conflict' => $conflict->id])
            ->actingAs($reviewer)
            ->withoutFollowingRedirects()
            ->method('acceptCentral')
            ->assertRedirect(route('platform.sync-conflicts.show', $conflict));

        $conflict->refresh();

        $this->assertSame(SyncConflict::STATUS_RESOLVED, $conflict->status);
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_CENTRAL, $conflict->resolution);
        $this->assertSame(NodeOperation::STATUS_CONFLICTED, $operation->fresh()->status);

        $audit = AuditEvent::query()->where('action', SyncConflictResolver::AUDIT_RESOLVED)->sole();

        $this->assertSame(SyncConflictResolver::SIDE_LOCAL, $audit->after_json['accepted_side']);
    }

    /**
     * A refused resolution leaves the queue row open rather than recording a
     * decision that did not take effect.
     */
    public function test_a_refused_resolution_leaves_the_conflict_open(): void
    {
        $this->registerApplier();
        [$conflict] = $this->resolvableConflict();
        $reviewer = $this->godModeUser();

        app(SyncConflictResolver::class)->acceptOnsite($conflict, $reviewer);

        $this->screen('platform.sync-conflicts.show', ['conflict' => $conflict->id])
            ->actingAs($reviewer)
            ->withoutFollowingRedirects()
            ->method('acceptCentral')
            ->assertRedirect(route('platform.sync-conflicts.show', $conflict));

        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_ONSITE, $conflict->fresh()->resolution);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_sync_conflict_screens_require_god_mode_permission(): void
    {
        $conflict = SyncConflict::factory()->create();
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $this->actingAs($user)
            ->get(route('platform.sync-conflicts'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('platform.sync-conflicts.show', $conflict))
            ->assertForbidden();
    }

    public function test_resolving_requires_god_mode_permission(): void
    {
        [$conflict] = $this->resolvableConflict();
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $this->screen('platform.sync-conflicts.show', ['conflict' => $conflict->id])
            ->actingAs($user)
            ->method('acceptOnsite')
            ->assertForbidden();

        $conflict->refresh();

        $this->assertTrue($conflict->isOpen());
        $this->assertNull($conflict->resolution);
        $this->assertDatabaseCount('audit_events', 0);
    }

    /**
     * A conflict on a central install between this node and a paired on-site
     * node, which is the shape both resolutions are named after.
     *
     * @return array{SyncConflict, NodeOperation}
     */
    private function resolvableConflict(): array
    {
        Node::factory()->central()->create();
        $onsite = Node::factory()->onsite()->remote()->create();

        $operation = NodeOperation::factory()->conflicted()->create([
            'origin_node_id' => $onsite->getKey(),
            'entity_type' => 'attendance_record',
            'failure_reason' => 'Local and remote attendance disagree.',
        ]);

        $conflict = SyncConflict::factory()->forOperation($operation)->create([
            'local_value_json' => ['status' => 'open'],
            'remote_value_json' => ['status' => 'no_show'],
            'reason' => 'Local and remote attendance disagree.',
        ]);

        return [$conflict, $operation];
    }

    private function registerApplier(): void
    {
        app(NodeOperationApplierRegistry::class)->register(new AttendanceConflictTestApplier);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.sync-conflicts' => true,
            ],
        ]);
    }
}

/**
 * A minimal applier so an accepted remote version has somewhere to be written.
 * Applier behaviour itself is covered by {@see SyncConflictResolverTest}.
 */
class AttendanceConflictTestApplier implements NodeOperationApplier
{
    public function supports(SignedNodeOperation $operation): bool
    {
        return $operation->entityType === 'attendance_record';
    }

    public function apply(SignedNodeOperation $operation): void
    {
        //
    }
}
