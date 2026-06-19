<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Permission;
use App\Models\PermissionRole;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_effective_roles_are_seeded_with_documented_scopes(): void
    {
        $expected = [
            'staff' => PermissionRole::SCOPE_ORGANIZATION,
            'shift_lead' => PermissionRole::SCOPE_TEAM,
            'department_lead' => PermissionRole::SCOPE_DEPARTMENT,
            'ic_lead' => PermissionRole::SCOPE_EVENT,
            'ic_operator' => PermissionRole::SCOPE_EVENT,
            'ic_viewer' => PermissionRole::SCOPE_EVENT,
            'organizer' => PermissionRole::SCOPE_ORGANIZATION,
            'lead_organizer' => PermissionRole::SCOPE_ORGANIZATION,
            'god_mode' => PermissionRole::SCOPE_NODE,
        ];

        $this->assertSame(
            array_keys($expected),
            PermissionRole::query()->orderBy('id')->pluck('code')->all(),
        );

        foreach ($expected as $code => $scopeType) {
            $this->assertSame(
                $scopeType,
                PermissionRole::query()->where('code', $code)->value('scope_type'),
                "Role {$code} should be {$scopeType}-scoped.",
            );
        }
    }

    public function test_registered_permissions_match_catalog(): void
    {
        $this->assertSame(
            array_keys(PermissionCatalog::permissions()),
            Permission::query()->orderBy('id')->pluck('code')->all(),
        );
    }

    public function test_ic_role_permissions_follow_technical_spec_section_16_2(): void
    {
        $this->assertSame([
            'incidents.view',
            'incidents.add_note',
            'incidents.close',
            'incidents.reopen',
            'incidents.link_field_report',
            'field_reports.view_event',
            'field_reports.download_photo',
        ], $this->permissionCodesFor('ic_lead'));

        // ic_operator matches ic_lead except it cannot download field report photos.
        $this->assertSame([
            'incidents.view',
            'incidents.add_note',
            'incidents.close',
            'incidents.reopen',
            'incidents.link_field_report',
            'field_reports.view_event',
        ], $this->permissionCodesFor('ic_operator'));
        $this->assertNotContains('field_reports.download_photo', $this->permissionCodesFor('ic_operator'));

        // ic_viewer can only view incidents and field reports.
        $this->assertSame([
            'incidents.view',
            'field_reports.view_event',
        ], $this->permissionCodesFor('ic_viewer'));
    }

    public function test_organizer_can_view_published_policies_but_not_incidents_or_field_reports(): void
    {
        foreach (['organizer', 'lead_organizer'] as $code) {
            $permissions = $this->permissionCodesFor($code);

            // ORG-016: organizers can view all published policy/procedure documents.
            $this->assertContains('policies.view_published', $permissions);

            // ORG-015: membership in the Organizers Department does not grant
            // access to all incidents or all field reports.
            $this->assertNotContains('incidents.view', $permissions);
            $this->assertNotContains('field_reports.view_event', $permissions);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $roleCount = PermissionRole::query()->count();
        $permissionCount = Permission::query()->count();

        (new PermissionCatalogSeeder)->run();
        (new PermissionCatalogSeeder)->run();

        $this->assertSame($roleCount, PermissionRole::query()->count());
        $this->assertSame($permissionCount, Permission::query()->count());
        $this->assertSame([
            'incidents.view',
            'field_reports.view_event',
        ], $this->permissionCodesFor('ic_viewer'));
    }

    /**
     * @return list<string>
     */
    private function permissionCodesFor(string $roleCode): array
    {
        return PermissionRole::query()
            ->where('code', $roleCode)
            ->firstOrFail()
            ->permissions()
            ->orderBy('permissions.id')
            ->pluck('code')
            ->all();
    }
}
