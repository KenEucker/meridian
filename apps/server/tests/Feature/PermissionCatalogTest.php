<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Permission;
use App\Models\PermissionRole;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'department_logistics' => PermissionRole::SCOPE_DEPARTMENT,
            'department_operations' => PermissionRole::SCOPE_DEPARTMENT,
            'department_administration' => PermissionRole::SCOPE_DEPARTMENT,
            'department_planning' => PermissionRole::SCOPE_DEPARTMENT,
            'department_operator' => PermissionRole::SCOPE_DEPARTMENT,
            'ic_lead' => PermissionRole::SCOPE_EVENT,
            'ic_operator' => PermissionRole::SCOPE_EVENT,
            'ic_viewer' => PermissionRole::SCOPE_EVENT,
            'organizer' => PermissionRole::SCOPE_ORGANIZATION,
            'lead_organizer' => PermissionRole::SCOPE_ORGANIZATION,
            'staff_coordinator' => PermissionRole::SCOPE_ORGANIZATION,
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
            'incidents.create',
            'incidents.update',
            'incidents.add_note',
            'incidents.close',
            'incidents.reopen',
            'incidents.link_field_report',
            'incidents.print',
            'field_reports.view_event',
            'field_reports.download_photo',
            // CRED-011 / M18.5: the one authority outside IMS an IC lead holds.
            // It reaches the catalog through this role rather than through the
            // event's Incident Command Department because the department is
            // what makes the grant `ic_lead` in the first place.
            'event.credentials.revoke',
        ], $this->permissionCodesFor('ic_lead'));

        // ic_operator matches ic_lead except it cannot download field report
        // photos or print incident PDFs.
        $this->assertSame([
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'incidents.add_note',
            'incidents.close',
            'incidents.reopen',
            'incidents.link_field_report',
            'field_reports.view_event',
        ], $this->permissionCodesFor('ic_operator'));
        $this->assertNotContains('field_reports.download_photo', $this->permissionCodesFor('ic_operator'));
        $this->assertNotContains('incidents.print', $this->permissionCodesFor('ic_operator'));
        // CRED-011 names the Incident Command Department *lead*, and revocation
        // stops there: categorizing an incident is not deciding who may work.
        $this->assertNotContains('event.credentials.revoke', $this->permissionCodesFor('ic_operator'));

        // ic_viewer can only view incidents and field reports.
        $this->assertSame([
            'incidents.view',
            'field_reports.view_event',
        ], $this->permissionCodesFor('ic_viewer'));
        $this->assertNotContains('incidents.print', $this->permissionCodesFor('ic_viewer'));
    }

    public function test_organizer_can_view_published_policies_and_manage_departments_and_staff_but_not_incidents_or_field_reports(): void
    {
        foreach (['organizer', 'lead_organizer'] as $code) {
            $permissions = $this->permissionCodesFor($code);

            // ORG-016: organizers can view all published policy/procedure documents.
            $this->assertContains('policies.view_published', $permissions);

            // ORG-002 / M11.12: organizers manage organization departments.
            $this->assertContains('organization.departments.manage', $permissions);

            // VOL-001 through VOL-006 / M11.14: organizers manage staff intake
            // and department lead selection through the product path.
            $this->assertContains('organization.staff.manage', $permissions);

            // TRAIN-001 through TRAIN-006 / M11.16: organizers manage
            // department trainings through the product path.
            $this->assertContains('department.trainings.manage', $permissions);

            // REPORT-001 / REPORT-006 / M13.1: organizers export event-wide
            // credential eligibility. REPORT-002 / M13.2: and the event-wide
            // shift roster, under its own permission. REPORT-003 / M13.3: and
            // the event-wide staff contact list — the permission grants the
            // export, while REPORT-010 / VOL-011 keep emergency contacts out of
            // the file it produces. REPORT-004 / M13.4: and event-wide actual
            // hours worked. REPORT-005 / CREDIT-005 / M13.6: and event-wide
            // credits earned with the basis each was calculated from.
            $this->assertContains('reports.credential_eligibility.export', $permissions);
            $this->assertContains('reports.shift_roster.export', $permissions);
            $this->assertContains('reports.staff_contact.export', $permissions);
            $this->assertContains('reports.hours_worked.export', $permissions);
            $this->assertContains('reports.credits_earned.export', $permissions);

            // POL-023 / POL-046 / POL-047 / M18.6: organizers say which
            // documents must be acknowledged at signup and training, and read
            // who has. It is not the authority to publish the document — that
            // follows whoever maintains its scope (POL-048) — and POL-026 and
            // POL-027 keep it from being authority over anybody's schedule.
            $this->assertContains('documents.acknowledgments.review', $permissions);

            // HOURS-007: reading hours is not correcting them, which stays with
            // the department attendance managers.
            $this->assertNotContains('department.attendance.manage', $permissions);

            // ORG-015: membership in the Organizers Department does not grant
            // access to all incidents or all field reports.
            $this->assertNotContains('incidents.view', $permissions);
            $this->assertNotContains('incidents.create', $permissions);
            $this->assertNotContains('incidents.update', $permissions);
            $this->assertNotContains('field_reports.view_event', $permissions);
        }
    }

    public function test_department_operational_roles_have_separate_capabilities(): void
    {
        $this->assertSame([
            'department.presence.manage',
            'department.attendance.manage',
            'department.equipment.manage',
        ], $this->permissionCodesFor('department_logistics'));

        $this->assertSame([
            'department.deployments.assign',
        ], $this->permissionCodesFor('department_operations'));

        // M11.16: department administration and department leads also manage
        // department trainings through the product path.
        // M15A.7 / BRAND-019: and their own department's branding profile —
        // logo, accent, and surface background only.
        // M13.1 / REPORT-007: and department-scoped credential eligibility
        // exports, which stay narrower than the organizer event-wide export.
        // M13.2 / REPORT-002: and department-scoped shift roster exports.
        // M13.3 / REPORT-003, REPORT-009, VOL-012: and their own department's
        // staff contact list, the one export permitted to carry emergency
        // contacts.
        // M13.4 / REPORT-004: and their own department's actual hours worked,
        // which reads what attendance recorded without granting the authority
        // to correct it (HOURS-007).
        // M13.6 / REPORT-005, CREDIT-005: and their own department's credits
        // earned, which reads a frozen ledger (CREDIT-004) without granting the
        // authority to run or redo a calculation.
        $this->assertSame([
            'department.administer',
            'department.trainings.manage',
            'department.branding.manage',
            'reports.credential_eligibility.export',
            'reports.shift_roster.export',
            'reports.staff_contact.export',
            'reports.hours_worked.export',
            'reports.credits_earned.export',
        ], $this->permissionCodesFor('department_administration'));

        // M11.13: department leads share department.administer for self-admin.
        // M18.13 / TEAM-015: department leads are authorized attendance
        // managers alongside department_logistics, so they carry
        // department.attendance.manage — and only that — of the Logistics set.
        $this->assertSame([
            'department.attendance.manage',
            'department.administer',
            'department.trainings.manage',
            'department.branding.manage',
            'reports.credential_eligibility.export',
            'reports.shift_roster.export',
            'reports.staff_contact.export',
            'reports.hours_worked.export',
            'reports.credits_earned.export',
        ], $this->permissionCodesFor('department_lead'));

        $this->assertSame([
            'department.schedule.manage',
        ], $this->permissionCodesFor('department_planning'));

        // M18.13 / TEAM-015: shift leads are attendance managers for their
        // department. The capability is the whole of the role's catalog entry,
        // and the role itself still resolves only for lead-designated
        // memberships (M11.17), so a plain member of a grant-bearing team
        // holds nothing.
        $this->assertSame([
            'department.attendance.manage',
        ], $this->permissionCodesFor('shift_lead'));
    }

    public function test_department_operator_carries_no_capabilities_until_its_owning_milestone(): void
    {
        // M18.10 registers the role so the Operator team designation has a
        // role to attach a grant to. Its capability set is owned by M18.10A
        // (section 4.8A), so holding it grants nothing yet.
        $this->assertSame([], $this->permissionCodesFor('department_operator'));
    }

    public function test_staff_coordinator_carries_application_review_and_nothing_else(): void
    {
        // M18.11 / TEAM-014 / requirements 4.4: application review, approval,
        // rejection, and deferral, and no other organizer governance
        // capability. The single-permission list is the requirement, not an
        // implementation detail — anything added here widens a role that
        // exists precisely because it is narrower than organizer.
        $this->assertSame(
            ['organization.applications.review'],
            $this->permissionCodesFor('staff_coordinator'),
        );

        // Organizers and Lead Organizers review through the same capability,
        // which is what lets one policy answer for the whole reviewer
        // population (APP-005; M18.20A reuses it for profile change requests).
        $this->assertContains('organization.applications.review', $this->permissionCodesFor('organizer'));
        $this->assertContains('organization.applications.review', $this->permissionCodesFor('lead_organizer'));
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

    public function test_seeder_restores_missing_role_permission_links(): void
    {
        $role = PermissionRole::query()->where('code', 'ic_operator')->firstOrFail();
        $permission = Permission::query()->where('code', 'incidents.create')->firstOrFail();

        DB::table('role_permissions')
            ->where('permission_role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->delete();

        $this->assertNotContains('incidents.create', $this->permissionCodesFor('ic_operator'));

        (new PermissionCatalogSeeder)->run();

        $this->assertContains('incidents.create', $this->permissionCodesFor('ic_operator'));
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
