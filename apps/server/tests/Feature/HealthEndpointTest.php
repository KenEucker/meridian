<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_status_and_version_payload(): void
    {
        config([
            'meridian.version' => '9.9.9-test',
            'meridian.config_schema_version' => 7,
        ]);

        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'environment' => 'testing',
            'server_version' => '9.9.9-test',
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
        ]);
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
}
