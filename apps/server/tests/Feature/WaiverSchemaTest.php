<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WaiverSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_waivers_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('waivers'));

        foreach ([
            'id',
            'organization_id',
            'scope_type',
            'scope_id',
            'name',
            'description',
            'expires_after_days',
            'created_at',
            'updated_at',
            'archived_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('waivers', $column), "waivers.{$column} missing");
        }

        foreach ([
            'signed_content',
            'document_content',
            'signed_document',
            'signature',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('waivers', $column), "waivers must not store signed contents: {$column}");
        }
    }

    public function test_waiver_completions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('waiver_completions'));

        foreach ([
            'id',
            'waiver_id',
            'staff_id',
            'completed_at',
            'expires_at',
            'recorded_by_user_id',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('waiver_completions', $column), "waiver_completions.{$column} missing");
        }

        $this->assertFalse(Schema::hasColumn('waiver_completions', 'updated_at'));

        foreach ([
            'signed_content',
            'document_content',
            'signed_document',
            'signature',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('waiver_completions', $column), "waiver_completions must not store signed contents: {$column}");
        }
    }

    public function test_waiver_belongs_to_organization_and_supports_scope_types(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = $department->defaultTeam;

        $organizationWaiver = Waiver::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);

        $departmentWaiver = Waiver::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => Waiver::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
        ]);

        $teamWaiver = Waiver::factory()->create([
            'organization_id' => $organization->id,
            'scope_type' => Waiver::SCOPE_TEAM,
            'scope_id' => $team->id,
        ]);

        $organizationWaiver->refresh()->load('organization');
        $departmentWaiver->refresh();
        $teamWaiver->refresh();

        $this->assertTrue($organizationWaiver->organization->is($organization));
        $this->assertTrue($organizationWaiver->isOrganizationScoped());
        $this->assertSame(Waiver::SCOPE_DEPARTMENT, $departmentWaiver->scope_type);
        $this->assertSame(Waiver::SCOPE_TEAM, $teamWaiver->scope_type);
        $this->assertTrue($organization->waivers->contains($organizationWaiver));
    }

    public function test_expiring_waiver_helpers(): void
    {
        $waiver = Waiver::factory()->expiresAfterDays(365)->create();

        $this->assertTrue($waiver->expires());
        $this->assertSame(365, $waiver->expires_after_days);
    }

    public function test_waiver_has_completions_relationship(): void
    {
        $waiver = Waiver::factory()->create();

        WaiverCompletion::factory()->create([
            'waiver_id' => $waiver->id,
            'staff_id' => Staff::factory()->create()->id,
        ]);

        $waiver->refresh()->load('completions');

        $this->assertCount(1, $waiver->completions);
    }

    public function test_active_scope_excludes_archived_waivers_without_deleting_them(): void
    {
        $active = Waiver::factory()->create();
        $archived = Waiver::factory()->archived()->create();

        $this->assertTrue(Waiver::query()->active()->whereKey($active)->exists());
        $this->assertFalse(Waiver::query()->active()->whereKey($archived)->exists());
        $this->assertTrue($archived->isArchived());
        $this->assertDatabaseHas('waivers', ['id' => $archived->id]);
    }
}
