<?php

namespace Tests\Feature;

use Tests\TestCase;

class PowerSyncDeviceCacheProjectionTest extends TestCase
{
    private string $syncConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncConfig = file_get_contents(base_path('../../deploy/powersync/sync-config.yaml'));
    }

    public function test_streams_are_automatically_scoped_from_the_authenticated_subject(): void
    {
        $this->assertStringContainsString("config:\n  edition: 3", $this->syncConfig);
        $this->assertStringContainsString('WHERE user_id = auth.user_id()', $this->syncConfig);

        foreach ([
            'regular_staff_cache',
            'shift_lead_cache',
            'department_lead_cache',
        ] as $stream) {
            $this->assertMatchesRegularExpression(
                '/^  '.preg_quote($stream, '/').":\n    auto_subscribe: true$/m",
                $this->syncConfig,
            );
        }

        $this->assertStringNotContainsString('subscription.parameter', $this->syncConfig);
        $this->assertStringNotContainsString('connection.parameter', $this->syncConfig);
    }

    public function test_regular_staff_projection_contains_only_owned_and_visible_current_schema_data(): void
    {
        $regularStaff = $this->stream('regular_staff_cache', 'shift_lead_cache');

        foreach ([
            'FROM staff_user',
            'FROM staff_organization_statuses',
            'FROM department_memberships',
            'FROM team_memberships',
            'FROM organizations',
            'FROM departments',
            'FROM teams',
            'FROM event_department_assignments',
            'FROM events',
            'FROM shift_assignments',
            'FROM shifts',
            'FROM policy_documents',
            'FROM procedure_documents',
            'FROM document_fragment_references',
            'FROM document_fragments',
            'FROM document_acknowledgment_requirements',
            'FROM document_acknowledgments',
        ] as $tableQuery) {
            $this->assertStringContainsString($tableQuery, $regularStaff);
        }

        $this->assertStringContainsString('WHERE staff_id IN current_staff_ids', $regularStaff);
        $this->assertStringContainsString('WHERE user_id = auth.user_id()', $regularStaff);
        $this->assertStringContainsString("WHERE state = 'published'", $this->syncConfig);
        $this->assertStringContainsString('document_id IN visible_policy_document_ids', $regularStaff);
        $this->assertStringContainsString('document_id IN visible_procedure_document_ids', $regularStaff);
    }

    public function test_lead_projections_require_active_role_grants_on_a_current_team(): void
    {
        $shiftLead = $this->stream('shift_lead_cache', 'department_lead_cache');
        $departmentLead = $this->stream('department_lead_cache');

        $this->assertStringContainsString("SELECT id FROM permission_roles WHERE code = 'shift_lead'", $shiftLead);
        $this->assertStringContainsString("SELECT id FROM permission_roles WHERE code = 'department_lead'", $departmentLead);

        foreach ([$shiftLead, $departmentLead] as $leadStream) {
            $this->assertStringContainsString(
                'team_grants.team_id IN current_team_ids',
                $leadStream,
            );
            $this->assertStringContainsString(
                'team_grants.revoked_at IS NULL',
                $leadStream,
            );
            $this->assertStringContainsString(
                'team_grants.event_id IS NULL',
                $leadStream,
            );
        }
        $this->assertStringContainsString('FROM shift_assignments', $shiftLead);
        $this->assertStringContainsString('FROM staff', $shiftLead);
        $this->assertStringContainsString('FROM department_memberships', $departmentLead);
        $this->assertStringContainsString('FROM policy_documents', $departmentLead);
        $this->assertStringContainsString('FROM procedure_documents', $departmentLead);
    }

    public function test_sensitive_and_server_only_data_is_not_projected(): void
    {
        foreach ([
            'FROM users',
            'FROM auth_identities',
            'FROM audit_events',
            'FROM permissions',
            'FROM role_permissions',
            'FROM devices',
            'FROM device_trusts',
            'FROM nodes',
            'FROM incidents',
            'FROM field_reports',
            'emergency_contact',
            'date_of_birth',
            'status_reason',
            'profile_picture',
        ] as $restrictedSource) {
            $this->assertStringNotContainsString($restrictedSource, $this->syncConfig);
        }

        $this->assertStringNotContainsString('SELECT * FROM staff', $this->syncConfig);
        $this->assertStringNotContainsString('SELECT * FROM staff_organization_statuses', $this->syncConfig);
        $this->assertStringNotContainsString('SELECT * FROM department_memberships', $this->syncConfig);
    }

    private function stream(string $start, ?string $end = null): string
    {
        $startOffset = strpos($this->syncConfig, "  {$start}:");

        $this->assertNotFalse($startOffset);

        if ($end === null) {
            return substr($this->syncConfig, $startOffset);
        }

        $endOffset = strpos($this->syncConfig, "  {$end}:", $startOffset);

        $this->assertNotFalse($endOffset);

        return substr($this->syncConfig, $startOffset, $endOffset - $startOffset);
    }
}
