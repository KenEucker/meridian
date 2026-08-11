<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_status_and_version_payload(): void
    {
        config([
            'meridian.version' => '9.9.9',
            'meridian.config_schema_version' => 7,
        ]);

        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'environment' => 'testing',
            'server_version' => '9.9.9',
            'config_schema_version' => 7,
        ]);
    }

    public function test_health_endpoint_exposes_expected_payload_keys(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'environment',
            'server_version',
            'config_schema_version',
            'timestamp',
            'node_role',
            'organization_id',
            'event_id',
            'event_name',
            'sync',
            'offline_read_set',
            'connected_devices',
        ]);
    }

    public function test_health_endpoint_answers_when_node_identity_is_unavailable(): void
    {
        // This is a liveness probe first. It has to answer on an install whose
        // database is unreachable or not yet migrated — exactly when someone
        // is checking it — so node identity degrades to null rather than
        // turning the probe into a 500.
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('node_role', null);
        $response->assertJsonPath('organization_id', null);
        $response->assertJsonPath('event_id', null);
        // The M19.5 panel facts degrade the same way: the probe answers with
        // nulls rather than failing when their tables cannot be read.
        $response->assertJsonPath('event_name', null);
        $response->assertJsonPath('sync', null);
        $response->assertJsonPath('connected_devices', null);
    }

    public function test_health_endpoint_is_reachable_without_authentication(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
    }

    public function test_health_config_defaults_are_defined(): void
    {
        $this->assertNotNull(config('meridian.version'));
        $this->assertIsInt(config('meridian.config_schema_version'));
    }

    public function test_health_config_version_comes_from_root_package_json(): void
    {
        $packageJson = json_decode((string) file_get_contents(base_path('../../package.json')), true);

        $this->assertIsArray($packageJson);
        $this->assertSame($packageJson['version'], config('meridian.version'));
    }
}
