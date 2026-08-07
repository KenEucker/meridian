<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\EventHorizonDismissal;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\Staff;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Hiding and restoring the Event Horizon (M18.44; HORIZON-012 through
 * HORIZON-015; technical spec 21D.8; data/API 10.21).
 *
 * The milestone's named expectations: hiding is refused server-side while an
 * item is outstanding rather than only hidden in the UI, a new outstanding
 * item returns the surface, and the preference is not audited and not
 * readable by another user.
 */
class EventHorizonDismissalTest extends TestCase
{
    use RefreshDatabase;

    public function test_hiding_is_refused_server_side_while_an_item_is_outstanding(): void
    {
        $scenario = $this->scenario();
        $this->givenOutstandingRequirement($scenario);

        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertStatus(409);

        $this->assertSame(0, EventHorizonDismissal::query()->count());
    }

    public function test_a_member_with_nothing_outstanding_hides_and_restores_their_own_surface(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertOk()
            ->assertJsonPath('hidden', true);

        $this->assertSame(1, EventHorizonDismissal::query()->count());
        $this->assertTrue($this->readFor($scenario['member'], $scenario)['hidden']);

        // Restoring deletes the row rather than writing a second state:
        // "not hidden" is the absence of a decision (data/API 10.21).
        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/show-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertOk()
            ->assertJsonPath('hidden', false);

        $this->assertSame(0, EventHorizonDismissal::query()->count());
        $this->assertFalse($this->readFor($scenario['member'], $scenario)['hidden']);
    }

    /**
     * HORIZON-015: an item becoming outstanding again returns the surface, and
     * the row is discarded rather than left to re-hide a list that had work on
     * it — completing the new item later does not re-hide the surface.
     */
    public function test_a_new_outstanding_item_returns_the_surface_and_discards_the_row(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertOk();

        $this->givenOutstandingRequirement($scenario);

        $this->assertFalse($this->readFor($scenario['member'], $scenario)['hidden']);
        $this->assertSame(0, EventHorizonDismissal::query()->count());
    }

    public function test_the_preference_is_not_audited_and_not_readable_by_another_user(): void
    {
        $scenario = $this->scenario();

        $auditBefore = AuditEvent::query()->count();

        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertOk();

        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/show-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertOk();

        // Personal view state is not a record of anything operational
        // (technical spec 21D.10): neither command wrote an audit event.
        $this->assertSame($auditBefore, AuditEvent::query()->count());

        // Another member's read reports their own preference, not the first
        // member's, and nothing in the payload names who hid what.
        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertOk();

        $other = $this->readFor($scenario['otherMember'], $scenario);
        $this->assertFalse($other['hidden']);
    }

    /**
     * Neither command accepts a subject staff member: the row written is
     * always the caller's own, so one member hiding cannot touch another's
     * menu (HORIZON-014).
     */
    public function test_hiding_acts_only_on_the_callers_own_preference(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient($scenario['member'])
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
                // Ignored: there is no subject parameter to smuggle one in as.
                'staff_id' => (string) $scenario['otherStaff']->id,
            ])
            ->assertOk();

        $rows = EventHorizonDismissal::query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $scenario['memberStaff']->id, (string) $rows[0]->staff_id);
    }

    public function test_a_caller_with_no_event_standing_cannot_hide(): void
    {
        $scenario = $this->scenario();

        $this->actingAsClient(User::factory()->create())
            ->postJson('/api/commands/hide-event-horizon', [
                'event_id' => (string) $scenario['event']->id,
            ])
            ->assertStatus(409);

        $this->assertSame(0, EventHorizonDismissal::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function readFor(User $user, array $scenario): array
    {
        return $this->actingAsClient($user)
            ->getJson("/api/events/{$scenario['event']->id}/event-horizon")
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function givenOutstandingRequirement(array $scenario): void
    {
        $policy = PolicyDocument::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'title' => 'Fire Safety Policy',
            'state' => PolicyDocument::STATE_PUBLISHED,
        ]);

        DocumentAcknowledgmentRequirement::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->id,
        ]);
    }

    /**
     * Two members of one department, nothing outstanding for either.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-06-17 12:00:00'));

        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);

        $event = Event::factory()->for($organization)->create([
            'active_event_window_starts_at' => Carbon::parse('2027-07-01 00:00:00'),
            'active_event_window_ends_at' => Carbon::parse('2027-07-10 00:00:00'),
        ]);

        $memberStaff = Staff::factory()->create(['handle' => 'mira']);
        $otherStaff = Staff::factory()->create(['handle' => 'noor']);

        foreach ([$memberStaff, $otherStaff] as $staff) {
            app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        }

        $member = User::factory()->create();
        $member->staffProfiles()->attach($memberStaff->id);

        $otherMember = User::factory()->create();
        $otherMember->staffProfiles()->attach($otherStaff->id);

        return [
            'organization' => $organization,
            'department' => $department,
            'event' => $event,
            'memberStaff' => $memberStaff,
            'otherStaff' => $otherStaff,
            'member' => $member,
            'otherMember' => $otherMember,
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
