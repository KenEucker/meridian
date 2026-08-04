<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God Mode credit configuration (M18.16).
 *
 * The support half of Ken's onboarding requirement: an operator standing up a
 * new organization, or helping one that is stuck, can set every credit value
 * from the console — the policies, the organization default, and a shift's
 * rate — through the same services and audit rows the product path uses.
 */
class CreditPolicyOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_credit_policy_list_shows_policies_with_their_use(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $policy = CreditPolicy::factory()->for($organization)->multiplier('1.500')->create([
            'name' => 'Standard Hour',
        ]);
        CreditPolicy::factory()->for($organization)->create([
            'name' => 'Retired Rate',
            'archived_at' => Carbon::parse('2026-06-01T00:00:00Z'),
        ]);
        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();

        // A shift-scoped custom rate stays off the catalog list.
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $shift = $this->shift($event, $department);
        CreditPolicy::factory()->for($organization)->create([
            'name' => 'Custom shift rate',
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAs($this->creditPolicyAdmin())
            ->get(route('platform.credit-policies'));

        $response->assertOk();
        $response->assertSee('Credit policies');
        $response->assertSee('Standard Hour');
        $response->assertSee('Northwood Collective');
        $response->assertSee('Organization default');
        $response->assertSee('Retired Rate');
        $response->assertSee('Archived');
        $response->assertDontSee('Custom shift rate');
    }

    public function test_orchid_creates_and_rerates_a_policy_through_the_admin_service(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->creditPolicyAdmin();

        $this->actingAs($admin)
            ->from(route('platform.credit-policies.create'))
            ->post(route('platform.credit-policies.create', ['method' => 'save']), [
                'creditPolicy' => [
                    'organization_id' => $organization->id,
                    'name' => 'Pre-event Build',
                    'credit_multiplier' => 0.5,
                ],
            ])
            ->assertRedirect(route('platform.credit-policies'));

        $policy = CreditPolicy::query()->where('name', 'Pre-event Build')->firstOrFail();
        $this->assertSame('0.500', (string) $policy->credit_multiplier);

        // The Orchid path writes the same audit row the product path does.
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'credit_policy.created')
                ->where('entity_id', $policy->id)
                ->where('source_context', AuditEvent::SOURCE_ORCHID)
                ->exists(),
        );

        $this->actingAs($admin)
            ->from(route('platform.credit-policies.edit', $policy->id))
            ->post(route('platform.credit-policies.edit', ['creditPolicy' => $policy->id, 'method' => 'save']), [
                'creditPolicy' => [
                    'name' => 'Pre-event Build',
                    'credit_multiplier' => '0.75',
                ],
            ])
            ->assertRedirect(route('platform.credit-policies'));

        $this->assertSame('0.750', (string) $policy->refresh()->credit_multiplier);
    }

    public function test_orchid_respects_the_governance_freeze_and_the_default_archive_refusal(): void
    {
        $organization = Organization::factory()->create();
        $policy = CreditPolicy::factory()->for($organization)->create(['name' => 'Standard Hour']);
        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();
        $admin = $this->creditPolicyAdmin();

        // The organization default cannot be archived while it holds that job,
        // in God Mode exactly as on the product surface.
        $this->actingAs($admin)
            ->from(route('platform.credit-policies.edit', $policy->id))
            ->post(route('platform.credit-policies.edit', ['creditPolicy' => $policy->id, 'method' => 'archive']));

        $this->assertNull($policy->refresh()->archived_at);

        // ORG-021: the active event window freezes policy edits here too.
        Event::factory()->for($organization)->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->from(route('platform.credit-policies.edit', $policy->id))
            ->post(route('platform.credit-policies.edit', ['creditPolicy' => $policy->id, 'method' => 'save']), [
                'creditPolicy' => [
                    'name' => 'Standard Hour',
                    'credit_multiplier' => 9,
                ],
            ])
            ->assertSessionHasErrors('creditPolicy.name');

        $this->assertSame('1.000', (string) $policy->refresh()->credit_multiplier);
    }

    public function test_orchid_organization_screen_sets_the_onboarding_configuration(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood']);
        $department = Department::factory()->for($organization)->create();
        $policy = CreditPolicy::factory()->for($organization)->create(['name' => 'Standard Hour']);
        $admin = $this->organizationAdmin();

        $this->actingAs($admin)
            ->from(route('platform.organizations.edit', $organization->id))
            ->post(route('platform.organizations.edit', ['organization' => $organization->id, 'method' => 'save']), [
                'organization' => [
                    'name' => $organization->name,
                    'slug' => 'northwood',
                    'hours_correction_grace_period_days' => 21,
                    'default_credit_policy_id' => $policy->id,
                    'organizers_department_id' => $department->id,
                    'default_placement_department_id' => $department->id,
                ],
            ])
            ->assertRedirect(route('platform.organizations'));

        $organization->refresh();
        $this->assertSame(21, $organization->hours_correction_grace_period_days);
        $this->assertSame((string) $policy->id, (string) $organization->default_credit_policy_id);
        $this->assertSame((string) $department->id, (string) $organization->organizers_department_id);
        $this->assertSame((string) $department->id, (string) $organization->default_placement_department_id);

        // Through the configuration service, so the change carries the same
        // audit row the organizer product surface writes.
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'organization.configuration_updated')
                ->where('entity_id', $organization->id)
                ->where('source_context', AuditEvent::SOURCE_ORCHID)
                ->exists(),
        );
    }

    public function test_orchid_shift_screen_sets_a_policy_and_a_custom_rate(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();
        $policy = CreditPolicy::factory()->for($organization)->create(['name' => 'Standard Hour']);
        $shift = $this->shift($event, $department, $team);
        $admin = $this->shiftAdmin();

        $payload = fn (array $credit): array => [
            'shift' => [
                'event_id' => $event->id,
                'department_id' => $department->id,
                'eligible_team_id' => $team->id,
                'title' => 'Gate Watch',
                'starts_at' => $shift->starts_at->toDateTimeString(),
                'ends_at' => $shift->ends_at->toDateTimeString(),
                ...$credit,
            ],
        ];

        $this->actingAs($admin)
            ->from(route('platform.shifts.edit', $shift->id))
            ->post(
                route('platform.shifts.edit', ['shift' => $shift->id, 'method' => 'save']),
                $payload(['credit_policy_id' => $policy->id]),
            )
            ->assertRedirect(route('platform.shifts'));

        $this->assertSame((string) $policy->id, (string) $shift->refresh()->credit_policy_id);

        // A custom rate replaces the named policy with the shift's own
        // shift-scoped row, the same row the product path writes.
        $this->actingAs($admin)
            ->from(route('platform.shifts.edit', $shift->id))
            ->post(
                route('platform.shifts.edit', ['shift' => $shift->id, 'method' => 'save']),
                $payload(['custom_credit_multiplier' => '0.5']),
            )
            ->assertRedirect(route('platform.shifts'));

        $shift->refresh();
        $this->assertSame('0.500', $shift->customCreditMultiplier());

        // Both at once is refused rather than guessed between.
        $this->actingAs($admin)
            ->from(route('platform.shifts.edit', $shift->id))
            ->post(
                route('platform.shifts.edit', ['shift' => $shift->id, 'method' => 'save']),
                $payload([
                    'credit_policy_id' => $policy->id,
                    'custom_credit_multiplier' => '1.5',
                ]),
            )
            ->assertSessionHasErrors('shift.custom_credit_multiplier');
    }

    public function test_an_operator_without_the_permission_cannot_reach_the_screen(): void
    {
        $withoutPermission = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($withoutPermission)
            ->get(route('platform.credit-policies'))
            ->assertForbidden();
    }

    private function shift(Event $event, Department $department, ?Team $team = null): Shift
    {
        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => ($team ?? Team::factory()->for($department)->create())->id,
            'title' => 'Gate Watch',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
        ]);
    }

    private function creditPolicyAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.credit-policies' => true,
            ],
        ]);
    }

    private function organizationAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.organizations' => true,
            ],
        ]);
    }

    private function shiftAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.shifts' => true,
            ],
        ]);
    }
}
