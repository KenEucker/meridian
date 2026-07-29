<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Credit policy and credit ledger schema (M13.5; ORG-009, ORG-010, SHIFT-010,
 * CREDIT-001 through CREDIT-005; data/API section 10.12).
 */
class CreditSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_policies_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('credit_policies'));

        foreach ([
            'id',
            'organization_id',
            'event_id',
            'shift_id',
            'name',
            'credit_multiplier',
            'created_at',
            'updated_at',
            'archived_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('credit_policies', $column), "credit_policies.{$column} missing");
        }
    }

    /**
     * ORG-010 says Meridian does not support department default credit
     * policies. The absence is enforced by there being no column to hold one,
     * not by a rule somebody has to remember.
     */
    public function test_credit_policies_have_no_department_default(): void
    {
        $this->assertFalse(Schema::hasColumn('credit_policies', 'department_id'));
        $this->assertFalse(Schema::hasColumn('departments', 'default_credit_policy_id'));
    }

    public function test_credit_ledger_entries_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('credit_ledger_entries'));

        foreach ([
            'id',
            'event_id',
            'department_id',
            'shift_id',
            'staff_id',
            'hours_worked_id',
            'credit_policy_id',
            'entry_type',
            'hours',
            'credits',
            'status',
            'calculation_basis',
            'created_by_user_id',
            'created_at',
            'frozen_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('credit_ledger_entries', $column), "credit_ledger_entries.{$column} missing");
        }
    }

    /**
     * The ledger is append-only: an entry records when it was written and never
     * when it was last changed, because it is not changed.
     */
    public function test_credit_ledger_entries_are_not_updated_in_place(): void
    {
        $this->assertFalse(Schema::hasColumn('credit_ledger_entries', 'updated_at'));
        $this->assertNull(CreditLedgerEntry::UPDATED_AT);
    }

    public function test_a_credit_policy_belongs_to_an_organization_and_may_scope_to_an_event_or_shift(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
        ]);

        $policy = CreditPolicy::factory()->for($organization)->create([
            'event_id' => $event->id,
            'shift_id' => $shift->id,
        ]);

        $policy->refresh()->load('organization', 'event', 'shift');

        $this->assertTrue($policy->organization->is($organization));
        $this->assertTrue($policy->event->is($event));
        $this->assertTrue($policy->shift->is($shift));
        $this->assertTrue($organization->creditPolicies->contains($policy));
    }

    public function test_an_organization_names_a_default_credit_policy(): void
    {
        $organization = Organization::factory()->create();
        $policy = CreditPolicy::factory()->for($organization)->create();

        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();

        $this->assertTrue($organization->refresh()->defaultCreditPolicy->is($policy));
    }

    public function test_a_shift_may_name_its_own_credit_policy(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        $policy = CreditPolicy::factory()->for($organization)->create();

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'credit_policy_id' => $policy->id,
        ]);

        $this->assertTrue($shift->refresh()->creditPolicy->is($policy));
        $this->assertNull(Shift::factory()->create()->credit_policy_id);
    }

    /**
     * One calculated entry per hours record is what makes a repeated
     * calculation safe: the database refuses the second row rather than
     * trusting every future caller to check first (CREDIT-004).
     */
    public function test_one_calculated_entry_per_hours_record(): void
    {
        $hours = HoursWorked::factory()->create();
        CreditLedgerEntry::factory()->create(['hours_worked_id' => $hours->id]);

        $this->expectException(QueryException::class);

        CreditLedgerEntry::factory()->create(['hours_worked_id' => $hours->id]);
    }

    /**
     * Finalization is a property of the hours record, not of the ledger: only a
     * record the correction grace period has closed on may be credited
     * (HOURS-008, CREDIT-001).
     */
    public function test_hours_report_their_own_finalization_and_carry_their_ledger_entries(): void
    {
        $open = HoursWorked::factory()->create(['frozen_at' => null]);
        $finalized = HoursWorked::factory()->create(['frozen_at' => now()]);
        $entry = CreditLedgerEntry::factory()->create(['hours_worked_id' => $finalized->id]);

        $this->assertFalse($open->isFinalized());
        $this->assertTrue($finalized->isFinalized());
        $this->assertTrue($finalized->refresh()->creditLedgerEntries->contains($entry));
        $this->assertTrue($entry->refresh()->hoursWorked->is($finalized));
    }

    public function test_an_archived_credit_policy_is_preserved_and_excluded_from_the_active_scope(): void
    {
        $active = CreditPolicy::factory()->create();
        $archived = CreditPolicy::factory()->archived()->create();

        $this->assertTrue(CreditPolicy::query()->active()->whereKey($active)->exists());
        $this->assertFalse(CreditPolicy::query()->active()->whereKey($archived)->exists());
        $this->assertTrue($archived->isArchived());
        $this->assertDatabaseHas('credit_policies', ['id' => $archived->id]);
    }
}
