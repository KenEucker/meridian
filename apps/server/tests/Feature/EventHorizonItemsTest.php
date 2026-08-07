<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftTrainingRequirement;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The five Event Horizon item kinds over seeded fixtures (M18.39 through
 * M18.42; HORIZON-003 through HORIZON-006, HORIZON-009).
 *
 * Every named milestone expectation is here: an expired waiver reads
 * outstanding rather than complete, an acknowledgment satisfied at an earlier
 * version stays complete (POL-045), an expired training reads outstanding, a
 * full shift produces no item, a closed cutoff produces no item, a shift the
 * member is already on reads complete rather than disappearing, a member who
 * leads no team sees no gap items, a lead sees gaps for their own teams only,
 * and no staff name appears in a gap item payload.
 */
class EventHorizonItemsTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Document acknowledgments (M18.39; POL-043 through POL-047)
    |--------------------------------------------------------------------------
    */

    public function test_an_unacknowledged_requirement_is_outstanding_and_links_to_the_acknowledgment_surface(): void
    {
        $scenario = $this->scenario();
        $this->givenRequirement($scenario, 'Fire Safety Policy');

        $item = $this->itemFor($scenario['member'], $scenario, 'document-acknowledgment');

        $this->assertSame('outstanding', $item['state']);
        $this->assertSame('Fire Safety Policy', $item['title']);
        $this->assertSame('staff.document-acknowledgments', $item['action']['surface']);
        $this->assertNull($item['due_at']);
    }

    /**
     * POL-045: a new document version re-requires nothing. The acknowledgment
     * was recorded against revision 1 and the document has moved to revision
     * 2, and the item stays complete.
     */
    public function test_an_acknowledgment_satisfied_at_an_earlier_version_stays_complete(): void
    {
        $scenario = $this->scenario();
        $policy = $this->givenRequirement($scenario, 'Radio Procedure');

        DocumentAcknowledgment::factory()->create([
            'user_id' => $scenario['member']->getKey(),
            'staff_id' => $scenario['memberStaff']->id,
            'document_type' => DocumentAcknowledgment::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->id,
            'scope_type' => DocumentAcknowledgment::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'document_revision' => 1,
        ]);

        $policy->update(['document_revision' => 2]);

        $item = $this->itemFor($scenario['member'], $scenario, 'document-acknowledgment');

        $this->assertSame('complete', $item['state']);
    }

    /*
    |--------------------------------------------------------------------------
    | Waivers (M18.39; WAIVER-003 through WAIVER-006, CRED-005)
    |--------------------------------------------------------------------------
    */

    public function test_a_waiver_with_no_completion_is_outstanding(): void
    {
        $scenario = $this->scenario();

        Waiver::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'name' => 'Liability Waiver',
        ]);

        $item = $this->itemFor($scenario['member'], $scenario, 'waiver');

        $this->assertSame('outstanding', $item['state']);
        $this->assertStringContainsString('no completion is on record', $item['evaluation']);
    }

    public function test_an_expired_waiver_reads_outstanding_rather_than_complete(): void
    {
        $scenario = $this->scenario();

        $waiver = Waiver::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => Waiver::SCOPE_DEPARTMENT,
            'scope_id' => $scenario['department']->id,
            'name' => 'Vehicle Waiver',
            'expires_after_days' => 365,
        ]);

        WaiverCompletion::factory()->create([
            'waiver_id' => $waiver->id,
            'staff_id' => $scenario['memberStaff']->id,
            'completed_at' => Carbon::now()->subYear(),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $item = $this->itemFor($scenario['member'], $scenario, 'waiver');

        $this->assertSame('outstanding', $item['state']);
        $this->assertStringContainsString('expired', $item['evaluation']);
        $this->assertStringContainsString('renew', strtolower($item['completion']));
    }

    public function test_a_current_waiver_completion_reads_complete_and_stays_listed(): void
    {
        $scenario = $this->scenario();

        $waiver = Waiver::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => Waiver::SCOPE_TEAM,
            'scope_id' => $scenario['team']->id,
            'name' => 'Night Ops Waiver',
        ]);

        WaiverCompletion::factory()->create([
            'waiver_id' => $waiver->id,
            'staff_id' => $scenario['memberStaff']->id,
            'completed_at' => Carbon::now()->subMonth(),
            'expires_at' => null,
        ]);

        $item = $this->itemFor($scenario['member'], $scenario, 'waiver');

        $this->assertSame('complete', $item['state']);
    }

    /*
    |--------------------------------------------------------------------------
    | Trainings (M18.40; TRAIN-002, TRAIN-008 through TRAIN-010, SHIFT-005)
    |--------------------------------------------------------------------------
    */

    public function test_an_online_training_links_to_its_training_page_and_an_in_person_one_to_session_signup(): void
    {
        $scenario = $this->scenario();

        Training::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'department_id' => $scenario['department']->id,
            'team_id' => null,
            'name' => 'Radio Basics',
            'delivery' => Training::DELIVERY_ONLINE,
            'online_url' => 'https://training.example/radio',
        ]);

        Training::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'department_id' => $scenario['department']->id,
            'team_id' => $scenario['team']->id,
            'name' => 'First Aid Session',
            'delivery' => Training::DELIVERY_IN_PERSON,
            'scheduled_start_at' => Carbon::now()->addDays(3),
        ]);

        $items = $this->itemsFor($scenario['member'], $scenario, 'training');
        $byTitle = collect($items)->keyBy('title');

        $online = $byTitle->get('Radio Basics');
        $this->assertSame('outstanding', $online['state']);
        $this->assertSame('department.training-detail', $online['action']['surface']);
        $this->assertSame('Open the training page', $online['action']['label']);

        $inPerson = $byTitle->get('First Aid Session');
        $this->assertSame('Open session signup', $inPerson['action']['label']);
        $this->assertSame(
            (string) $scenario['department']->id,
            $inPerson['action']['params']['department_id'],
        );
    }

    public function test_an_expired_training_reads_outstanding_and_says_it_expired(): void
    {
        $scenario = $this->scenario();

        $training = Training::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'department_id' => $scenario['department']->id,
            'team_id' => null,
            'name' => 'De-escalation',
            'expires_after_days' => 365,
        ]);

        TrainingCompletion::factory()->create([
            'training_id' => $training->id,
            'staff_id' => $scenario['memberStaff']->id,
            'completed_at' => Carbon::now()->subYears(2),
            'expires_at' => Carbon::now()->subMonth(),
        ]);

        $item = $this->itemFor($scenario['member'], $scenario, 'training');

        $this->assertSame('outstanding', $item['state']);
        $this->assertStringContainsString('expired', $item['evaluation']);
    }

    /**
     * A training required by a held shift carries that shift's start as its
     * deadline — the moment TRAIN-008 makes the completion matter.
     */
    public function test_a_shift_required_training_carries_the_shift_start_as_its_deadline(): void
    {
        $scenario = $this->scenario();

        $training = Training::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'department_id' => $scenario['department']->id,
            'team_id' => null,
            'name' => 'Gate Protocol',
        ]);

        $shift = $this->givenShift($scenario, [
            'title' => 'Gate Watch',
            'starts_at' => Carbon::parse('2027-07-03 08:00:00'),
            'ends_at' => Carbon::parse('2027-07-03 16:00:00'),
        ]);

        ShiftTrainingRequirement::query()->create([
            'shift_id' => $shift->id,
            'training_id' => $training->id,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $scenario['memberStaff']->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $item = $this->itemFor($scenario['member'], $scenario, 'training');

        $this->assertSame('Gate Protocol', $item['title']);
        $this->assertSame(
            Carbon::parse('2027-07-03 08:00:00')->toIso8601String(),
            $item['due_at'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shift signup (M18.41; SHIFT-004, SHIFT-007, SHIFT-008, SHIFT-011)
    |--------------------------------------------------------------------------
    */

    public function test_an_open_shift_with_capacity_is_outstanding_and_a_full_or_closed_one_produces_no_item(): void
    {
        $scenario = $this->scenario();

        $open = $this->givenShift($scenario, [
            'title' => 'Open Patrol',
            'capacity' => 3,
            'signup_closes_at' => Carbon::parse('2027-07-02 00:00:00'),
        ]);

        // Full: capacity 1, already taken by somebody else.
        $full = $this->givenShift($scenario, ['title' => 'Full Patrol', 'capacity' => 1]);
        ShiftAssignment::factory()->create([
            'shift_id' => $full->id,
            'staff_id' => Staff::factory()->create()->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        // Closed: its signup window ended before now.
        $this->givenShift($scenario, [
            'title' => 'Closed Patrol',
            'capacity' => 5,
            'signup_closes_at' => Carbon::now()->subHour(),
        ]);

        $items = $this->itemsFor($scenario['member'], $scenario, 'shift-signup');
        $titles = array_column($items, 'title');

        $this->assertSame(['Open Patrol'], $titles);
        $this->assertSame('outstanding', $items[0]['state']);
        $this->assertSame('staff.shift-board', $items[0]['action']['surface']);
        // Ordered by how soon it closes: the signup close is the deadline.
        $this->assertSame(
            Carbon::parse('2027-07-02 00:00:00')->toIso8601String(),
            $items[0]['due_at'],
        );
    }

    public function test_a_shift_the_member_is_already_on_reads_complete_rather_than_disappearing(): void
    {
        $scenario = $this->scenario();

        $shift = $this->givenShift($scenario, ['title' => 'Held Patrol', 'capacity' => 2]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $scenario['memberStaff']->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $item = $this->itemFor($scenario['member'], $scenario, 'shift-signup');

        $this->assertSame('complete', $item['state']);
        $this->assertSame('Held Patrol', $item['title']);
    }

    /*
    |--------------------------------------------------------------------------
    | Coverage gaps (M18.42; HORIZON-009; SHIFT-007; requirements 4.7)
    |--------------------------------------------------------------------------
    */

    public function test_a_lead_sees_gaps_for_their_own_teams_only_and_no_staff_name_appears(): void
    {
        $scenario = $this->scenario();

        // A named person on the lead's under-capacity shift, so the payload
        // has a name it must not carry.
        $named = Staff::factory()->create([
            'legal_name' => 'Zephyrine Quill',
            'handle' => 'zephyrine',
        ]);

        $ledShift = $this->givenShift($scenario, [
            'title' => 'Led Team Patrol',
            'eligible_team_id' => $scenario['ledTeam']->id,
            'capacity' => 4,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $ledShift->id,
            'staff_id' => $named->id,
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        // Another team's under-capacity shift, not the lead's to read.
        $this->givenShift($scenario, [
            'title' => 'Other Team Patrol',
            'eligible_team_id' => $scenario['team']->id,
            'capacity' => 4,
        ]);

        $items = $this->itemsFor($scenario['teamLead'], $scenario, 'coverage-gap');

        $this->assertCount(1, $items);
        $this->assertSame('Led Team Patrol', $items[0]['title']);
        $this->assertSame('outstanding', $items[0]['state']);
        $this->assertStringContainsString('3 of 4', $items[0]['evaluation']);

        $payload = json_encode($items, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Zephyrine', $payload);
        $this->assertStringNotContainsString('zephyrine', $payload);
    }

    public function test_a_member_who_leads_no_team_sees_no_gap_items(): void
    {
        $scenario = $this->scenario();

        $this->givenShift($scenario, [
            'title' => 'Understaffed Patrol',
            'capacity' => 4,
        ]);

        $response = $this->readFor($scenario['member'], $scenario);

        $this->assertNotContains(
            'coverage_gap',
            array_column($response->json('kinds'), 'id'),
        );
        $this->assertSame([], array_values(array_filter(
            $response->json('items'),
            fn (array $item): bool => $item['kind'] === 'coverage_gap',
        )));
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering (HORIZON-006)
    |--------------------------------------------------------------------------
    */

    /**
     * Outstanding before complete; within each, soonest deadline first with
     * undated items after dated ones; catalogue order breaks ties.
     */
    public function test_items_order_outstanding_first_then_by_soonest_deadline_then_catalogue_order(): void
    {
        $scenario = $this->scenario();

        // Undated outstanding, catalogue position 1.
        $this->givenRequirement($scenario, 'Fire Safety Policy');

        // Dated outstanding closing July 4, catalogue position 4.
        $this->givenShift($scenario, [
            'title' => 'Later Patrol',
            'capacity' => 3,
            'signup_closes_at' => Carbon::parse('2027-07-04 00:00:00'),
        ]);

        // Dated outstanding closing July 2 — sooner, so it comes first.
        $this->givenShift($scenario, [
            'title' => 'Sooner Patrol',
            'capacity' => 3,
            'signup_closes_at' => Carbon::parse('2027-07-02 00:00:00'),
        ]);

        // Complete, and last, whatever its kind's catalogue position.
        $waiver = Waiver::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'name' => 'Signed Waiver',
        ]);
        WaiverCompletion::factory()->create([
            'waiver_id' => $waiver->id,
            'staff_id' => $scenario['memberStaff']->id,
            'completed_at' => Carbon::now()->subMonth(),
            'expires_at' => null,
        ]);

        $items = $this->readFor($scenario['member'], $scenario)->json('items');

        $this->assertSame([
            ['Sooner Patrol', 'outstanding'],
            ['Later Patrol', 'outstanding'],
            ['Fire Safety Policy', 'outstanding'],
            ['Signed Waiver', 'complete'],
        ], array_map(
            fn (array $item): array => [$item['title'], $item['state']],
            $items,
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Scenario
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function readFor(User $user, array $scenario): TestResponse
    {
        return $this->actingAsClient($user)
            ->getJson("/api/events/{$scenario['event']->id}/event-horizon")
            ->assertOk();
    }

    /**
     * The single item whose identity starts with the given prefix.
     *
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function itemFor(User $user, array $scenario, string $identityPrefix): array
    {
        $items = $this->itemsFor($user, $scenario, $identityPrefix);

        $this->assertCount(1, $items, "expected exactly one {$identityPrefix} item");

        return $items[0];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return list<array<string, mixed>>
     */
    private function itemsFor(User $user, array $scenario, string $identityPrefix): array
    {
        return array_values(array_filter(
            $this->readFor($user, $scenario)->json('items'),
            fn (array $item): bool => str_starts_with($item['identity'], $identityPrefix.':'),
        ));
    }

    /**
     * An organization-scoped acknowledgment requirement on a published policy.
     *
     * @param  array<string, mixed>  $scenario
     */
    private function givenRequirement(array $scenario, string $title): PolicyDocument
    {
        $policy = PolicyDocument::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'title' => $title,
            'state' => PolicyDocument::STATE_PUBLISHED,
            'document_revision' => 1,
        ]);

        DocumentAcknowledgmentRequirement::factory()->create([
            'organization_id' => $scenario['organization']->id,
            'scope_type' => DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION,
            'scope_id' => $scenario['organization']->id,
            'document_type' => DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY,
            'document_id' => $policy->id,
        ]);

        return $policy;
    }

    /**
     * A future shift for the member's team unless overridden.
     *
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $attributes
     */
    private function givenShift(array $scenario, array $attributes = []): Shift
    {
        return Shift::factory()->create([
            'event_id' => $scenario['event']->id,
            'department_id' => $scenario['department']->id,
            'eligible_team_id' => $scenario['team']->id,
            'starts_at' => Carbon::parse('2027-07-05 08:00:00'),
            'ends_at' => Carbon::parse('2027-07-05 16:00:00'),
            'capacity' => null,
            ...$attributes,
        ]);
    }

    /**
     * One event two weeks out from its active window (inside the 30-day
     * lead-up), a member on the department's default team, and a lead who
     * leads a second team.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2027-06-17 12:00:00'));

        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2027',
            'timezone' => 'America/Los_Angeles',
            'active_event_window_starts_at' => Carbon::parse('2027-07-01 00:00:00'),
            'active_event_window_ends_at' => Carbon::parse('2027-07-10 00:00:00'),
        ]);

        $memberStaff = Staff::factory()->create(['handle' => 'mira']);
        $leadStaff = Staff::factory()->create(['handle' => 'lee']);

        app(DepartmentMembershipService::class)
            ->assignStaffWithDefaultTeam($memberStaff, $department);
        $leadMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($leadStaff)
            ->create(['status' => DepartmentMembership::STATUS_ACTIVE]);

        $department->load('defaultTeam');
        $team = $department->defaultTeam;

        $ledTeam = Team::factory()->for($department)->create(['name' => 'Night Watch']);

        TeamMembership::factory()->create([
            'team_id' => $ledTeam->id,
            'staff_id' => $leadStaff->id,
            'department_membership_id' => $leadMembership->id,
            'membership_role' => 'lead',
        ]);

        $member = User::factory()->create();
        $member->staffProfiles()->attach($memberStaff->id);

        $teamLead = User::factory()->create();
        $teamLead->staffProfiles()->attach($leadStaff->id);

        return [
            'organization' => $organization,
            'department' => $department,
            'event' => $event,
            'team' => $team,
            'ledTeam' => $ledTeam,
            'memberStaff' => $memberStaff,
            'leadStaff' => $leadStaff,
            'member' => $member,
            'teamLead' => $teamLead,
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
