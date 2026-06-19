<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('staff'));
        $this->assertTrue(Schema::hasColumn('staff', 'id'));
        $this->assertTrue(Schema::hasColumn('staff', 'legal_name'));
        $this->assertTrue(Schema::hasColumn('staff', 'preferred_name'));
        $this->assertTrue(Schema::hasColumn('staff', 'handle'));
        $this->assertTrue(Schema::hasColumn('staff', 'formerly_known_as'));
        $this->assertTrue(Schema::hasColumn('staff', 'email'));
        $this->assertTrue(Schema::hasColumn('staff', 'phone'));
        $this->assertTrue(Schema::hasColumn('staff', 'city'));
        $this->assertTrue(Schema::hasColumn('staff', 'state'));
        $this->assertTrue(Schema::hasColumn('staff', 'date_of_birth'));
        $this->assertTrue(Schema::hasColumn('staff', 'emergency_contact_name'));
        $this->assertTrue(Schema::hasColumn('staff', 'emergency_contact_phone'));
        $this->assertTrue(Schema::hasColumn('staff', 'profile_picture_path'));
        $this->assertTrue(Schema::hasColumn('staff', 'profile_picture_mime_type'));
        $this->assertTrue(Schema::hasColumn('staff', 'profile_picture_size_bytes'));
        $this->assertTrue(Schema::hasColumn('staff', 'profile_picture_width'));
        $this->assertTrue(Schema::hasColumn('staff', 'profile_picture_height'));
        $this->assertTrue(Schema::hasColumn('staff', 'profile_picture_uploaded_at'));
        $this->assertTrue(Schema::hasColumn('staff', 'created_at'));
        $this->assertTrue(Schema::hasColumn('staff', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('staff', 'archived_at'));
    }

    public function test_staff_organization_statuses_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('staff_organization_statuses'));
        $this->assertFalse(Schema::hasTable('organization_staff'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'id'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'staff_id'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'status'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'status_reason'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'status_changed_at'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'status_changed_by_user_id'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'created_at'));
        $this->assertTrue(Schema::hasColumn('staff_organization_statuses', 'updated_at'));
    }

    public function test_staff_can_link_to_users_without_department_or_team_membership(): void
    {
        $staff = Staff::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $staff->users()->attach([$firstUser->id, $secondUser->id]);
        $staff->refresh()->load('users');
        $firstUser->refresh()->load('staffProfiles');

        $this->assertCount(2, $staff->users);
        $this->assertTrue($staff->users->contains($firstUser));
        $this->assertTrue($firstUser->staffProfiles->contains($staff));
        $this->assertFalse(Schema::hasTable('department_staff'));
        $this->assertFalse(Schema::hasTable('team_staff'));
    }

    public function test_staff_organization_status_belongs_to_organization_and_staff(): void
    {
        $organization = Organization::factory()->create();
        $staff = Staff::factory()->create();
        $changedBy = User::factory()->create();

        $record = StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->for($changedBy, 'statusChangedBy')
            ->active()
            ->create([
                'status_reason' => 'Completed onboarding.',
            ]);

        $organization->refresh()->load('staffOrganizationStatuses', 'staff');
        $staff->refresh()->load('organizationStatuses', 'organizations');
        $record->refresh()->load('organization', 'staff', 'statusChangedBy');

        $this->assertTrue($record->organization->is($organization));
        $this->assertTrue($record->staff->is($staff));
        $this->assertTrue($record->statusChangedBy->is($changedBy));
        $this->assertTrue($organization->staffOrganizationStatuses->contains($record));
        $this->assertTrue($organization->staff->contains($staff));
        $this->assertTrue($staff->organizations->contains($organization));
        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $record->status);
    }

    public function test_staff_email_is_unique(): void
    {
        Staff::factory()->create([
            'email' => 'signal@example.org',
        ]);

        $this->expectException(QueryException::class);

        Staff::factory()->create([
            'email' => 'signal@example.org',
        ]);
    }

    public function test_staff_has_one_status_record_per_organization(): void
    {
        $organization = Organization::factory()->create();
        $staff = Staff::factory()->create();

        StaffOrganizationStatus::factory()->for($organization)->for($staff)->create();

        $this->expectException(QueryException::class);

        StaffOrganizationStatus::factory()->for($organization)->for($staff)->active()->create();
    }

    public function test_active_scope_excludes_archived_staff_without_deleting_them(): void
    {
        $activeStaff = Staff::factory()->create();
        $archivedStaff = Staff::factory()->archived()->create();

        $this->assertTrue(Staff::query()->active()->whereKey($activeStaff)->exists());
        $this->assertFalse(Staff::query()->active()->whereKey($archivedStaff)->exists());
        $this->assertDatabaseHas('staff', [
            'id' => $archivedStaff->id,
        ]);
        $this->assertTrue($archivedStaff->isArchived());
        $this->assertFalse($activeStaff->isArchived());
    }
}
