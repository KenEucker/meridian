<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasColumn('organizations', 'name'));
        $this->assertTrue(Schema::hasColumn('organizations', 'slug'));
        $this->assertTrue(Schema::hasColumn('organizations', 'default_ic_department_id'));
        $this->assertTrue(Schema::hasColumn('organizations', 'default_credit_policy_id'));
        $this->assertTrue(Schema::hasColumn('organizations', 'active_inactive_threshold_years'));
        $this->assertTrue(Schema::hasColumn('organizations', 'prospective_inactive_threshold_years'));
        $this->assertTrue(Schema::hasColumn('organizations', 'calendar_year_start_month'));
        $this->assertTrue(Schema::hasColumn('organizations', 'calendar_year_start_day'));
        $this->assertTrue(Schema::hasColumn('organizations', 'hours_correction_grace_period_days'));
        $this->assertTrue(Schema::hasColumn('organizations', 'default_placement_department_id'));
        $this->assertTrue(Schema::hasColumn('organizations', 'created_at'));
        $this->assertTrue(Schema::hasColumn('organizations', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('organizations', 'archived_at'));
    }

    public function test_the_grace_period_defaults_to_fourteen_days(): void
    {
        // ORG-017's documented default: an organization that has never opened
        // the configuration surface still has a grace period.
        $organization = Organization::factory()->create();

        $this->assertSame(14, $organization->refresh()->hours_correction_grace_period_days);
    }

    public function test_organization_slug_is_unique(): void
    {
        Organization::factory()->create([
            'slug' => 'northwood-collective',
        ]);

        $this->expectException(QueryException::class);

        Organization::factory()->create([
            'slug' => 'northwood-collective',
        ]);
    }

    public function test_active_scope_excludes_archived_organizations(): void
    {
        $activeOrganization = Organization::factory()->create();

        Organization::factory()->archived()->create();

        $this->assertTrue(Organization::query()->active()->first()->is($activeOrganization));
        $this->assertFalse($activeOrganization->isArchived());
    }
}
