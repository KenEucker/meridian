<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Node;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Branding\BrandingAccess;
use App\Support\LocalFieldFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The local QA fixture grants what the branding surfaces need (M15A.6,
 * M15A.7; BRAND-019).
 *
 * The client's department switcher is fixture data and the server's permission
 * checks are not. Before these grants existed, switching to "Organizer" changed
 * what the client offered without changing what the server would allow, so a
 * logo upload was offered and then refused with a 403. These tests exist to
 * keep the two descriptions of the same person in step.
 */
class LocalFieldFixtureBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixture(): User
    {
        $this->artisan('meridian:seed-local-field-fixture')->assertSuccessful();

        return User::query()->findOrFail(LocalFieldFixture::USER_ID);
    }

    public function test_the_fixture_user_may_edit_organization_branding(): void
    {
        $user = $this->seedFixture();
        $organization = Organization::query()->findOrFail(LocalFieldFixture::ORGANIZATION_ID);

        $this->assertTrue(
            app(BrandingAccess::class)->canManageOrganizationBranding($user, $organization),
            'The fixture user must hold organizer authority, or the branding screen offers an upload the server refuses.',
        );
    }

    public function test_the_fixture_user_may_edit_rangers_branding(): void
    {
        $user = $this->seedFixture();
        $rangers = Department::query()->findOrFail(LocalFieldFixture::DEPARTMENT_ID);

        $this->assertTrue(
            app(BrandingAccess::class)->canManageDepartmentBranding($user, $rangers),
        );
    }

    public function test_every_department_the_switcher_offers_exists_server_side(): void
    {
        $this->seedFixture();

        foreach ([
            LocalFieldFixture::DEPARTMENT_ID,
            LocalFieldFixture::ORGANIZER_DEPARTMENT_ID,
            LocalFieldFixture::GATE_DEPARTMENT_ID,
            LocalFieldFixture::DPW_DEPARTMENT_ID,
        ] as $departmentId) {
            $department = Department::query()->find($departmentId);

            $this->assertNotNull($department, "Switchable department {$departmentId} is missing.");
            $this->assertSame(LocalFieldFixture::ORGANIZATION_ID, (string) $department->organization_id);
        }
    }

    public function test_each_department_has_exactly_one_default_team_carrying_its_grants(): void
    {
        // TEAM-002 gives every department a default team on create. The
        // fixture hangs its role grants off that team rather than creating a
        // second one, so a department must not end up with two.
        $this->seedFixture();

        foreach ([
            LocalFieldFixture::DEPARTMENT_ID,
            LocalFieldFixture::ORGANIZER_DEPARTMENT_ID,
            LocalFieldFixture::GATE_DEPARTMENT_ID,
            LocalFieldFixture::DPW_DEPARTMENT_ID,
        ] as $departmentId) {
            $department = Department::query()->findOrFail($departmentId);

            $this->assertNotNull($department->default_team_id);

            $this->assertSame(
                1,
                Team::query()
                    ->where('department_id', $departmentId)
                    ->where('is_default', true)
                    ->whereNull('archived_at')
                    ->count(),
                "Department {$departmentId} should have exactly one active default team.",
            );
        }
    }

    public function test_the_denial_paths_stay_denied(): void
    {
        // A fixture where everything is permitted tests nothing. Gate is plain
        // staff and DPW is a team lead; BRAND-019 gives neither branding
        // authority over their department.
        $user = $this->seedFixture();
        $access = app(BrandingAccess::class);

        // The fixture user also holds organizer authority, which reaches every
        // department in the organization by design, so the denial is asserted
        // for a user who holds only the department-scoped roles.
        $stranger = User::factory()->create();

        foreach ([LocalFieldFixture::GATE_DEPARTMENT_ID, LocalFieldFixture::DPW_DEPARTMENT_ID] as $departmentId) {
            $this->assertFalse(
                $access->canManageDepartmentBranding(
                    $stranger,
                    Department::query()->findOrFail($departmentId),
                ),
            );
        }

        $this->assertFalse($access->canManageOrganizationBranding(
            $stranger,
            Organization::query()->findOrFail(LocalFieldFixture::ORGANIZATION_ID),
        ));

        // Sanity: the seeded user is the one that may.
        $this->assertTrue($access->canManageOrganizationBranding(
            $user,
            Organization::query()->findOrFail(LocalFieldFixture::ORGANIZATION_ID),
        ));
    }

    public function test_the_fixture_never_replaces_another_seeds_local_node(): void
    {
        // An install has one local node, and whichever row is local decides
        // which event the install is locked to. A database seeded by the
        // development scenario already has its node; the fixture landing a
        // second one locked to the fixture event broke session resolution for
        // every scenario account. The fixture yields: its rows still seed, the
        // other seed's node stands, and no fixture node appears beside it.
        $existing = Node::query()->create([
            'id' => (string) Str::uuid(),
            'node_name' => 'Northwood Development Node',
            'node_role' => Node::ROLE_DEVELOPMENT,
            'is_local' => true,
            'public_key' => base64_encode(str_repeat('N', 32)),
        ]);

        $this->artisan('meridian:seed-local-field-fixture')->assertSuccessful();

        $this->assertNull(Node::query()->find(LocalFieldFixture::NODE_ID));
        $this->assertSame(
            [(string) $existing->id],
            Node::query()->where('is_local', true)->pluck('id')->map(fn ($id) => (string) $id)->all(),
        );

        // On a database of its own — the fixture's intended home — the node
        // seeds exactly as before.
        $existing->delete();
        $this->artisan('meridian:seed-local-field-fixture')->assertSuccessful();
        $this->assertNotNull(Node::query()->find(LocalFieldFixture::NODE_ID));
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seedFixture();
        $this->artisan('meridian:seed-local-field-fixture')->assertSuccessful();

        $this->assertSame(
            4,
            Department::query()->where('organization_id', LocalFieldFixture::ORGANIZATION_ID)->count(),
        );

        $user = User::query()->findOrFail(LocalFieldFixture::USER_ID);

        $this->assertTrue(app(BrandingAccess::class)->canManageOrganizationBranding(
            $user,
            Organization::query()->findOrFail(LocalFieldFixture::ORGANIZATION_ID),
        ));
    }

    public function test_the_organizers_department_is_recorded_on_the_organization(): void
    {
        $this->seedFixture();

        $this->assertSame(
            LocalFieldFixture::ORGANIZER_DEPARTMENT_ID,
            (string) Organization::query()->findOrFail(LocalFieldFixture::ORGANIZATION_ID)->organizers_department_id,
        );
    }
}
