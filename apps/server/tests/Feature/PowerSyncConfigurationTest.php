<?php

namespace Tests\Feature;

use App\Services\PowerSync\PowerSyncHealthClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PowerSyncConfigurationTest extends TestCase
{
    public function test_server_configuration_exposes_the_powersync_integration_boundary(): void
    {
        $this->assertSame('http://127.0.0.1:8080', config('powersync.endpoint'));
        $this->assertSame('/probes/liveness', config('powersync.liveness_path'));
        $this->assertSame(2.0, config('powersync.request_timeout_seconds'));

        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^POWERSYNC_URL=http:\/\/127\.0\.0\.1:8080$/m', $env);
        $this->assertMatchesRegularExpression('/^POWERSYNC_LIVENESS_PATH=\/probes\/liveness$/m', $env);
    }

    public function test_health_client_reports_a_successful_liveness_probe(): void
    {
        config([
            'powersync.endpoint' => 'http://powersync.test/',
            'powersync.liveness_path' => '/probes/liveness',
        ]);

        Http::fake([
            'http://powersync.test/probes/liveness' => Http::response(['status' => 'ok']),
        ]);

        $this->assertTrue(app(PowerSyncHealthClient::class)->isAvailable());

        Http::assertSentCount(1);
    }

    public function test_health_client_fails_closed_for_an_unavailable_service(): void
    {
        config([
            'powersync.endpoint' => 'http://powersync.test',
            'powersync.liveness_path' => '/probes/liveness',
        ]);

        Http::fake([
            'http://powersync.test/probes/liveness' => Http::response([], 503),
        ]);

        $this->assertFalse(app(PowerSyncHealthClient::class)->isAvailable());
    }

    public function test_health_client_does_not_make_a_request_without_an_endpoint(): void
    {
        config(['powersync.endpoint' => null]);
        Http::fake();

        $this->assertFalse(app(PowerSyncHealthClient::class)->isAvailable());

        Http::assertNothingSent();
    }

    public function test_deploy_config_pins_the_approved_service_and_exposes_no_streams(): void
    {
        $compose = file_get_contents(base_path('../../deploy/powersync/compose.yaml'));
        $service = file_get_contents(base_path('../../deploy/powersync/service.yaml'));
        $syncConfig = file_get_contents(base_path('../../deploy/powersync/sync-config.yaml'));
        $exampleEnv = file_get_contents(base_path('../../deploy/powersync/.env.example'));

        $this->assertStringContainsString('journeyapps/powersync-service:1.22.0', $compose);
        $this->assertStringContainsString('POWERSYNC_CONFIG_PATH: /config/service.yaml', $compose);
        $this->assertStringContainsString('replication:', $service);
        $this->assertStringContainsString('storage:', $service);
        $this->assertStringContainsString('sync_config:', $service);
        $this->assertStringContainsString('client_auth:', $service);
        $this->assertMatchesRegularExpression('/^streams: \{\}$/m', $syncConfig);
        $this->assertStringNotContainsString('replace-me', $compose);
        $this->assertStringContainsString('replace-me', $exampleEnv);
    }
}
