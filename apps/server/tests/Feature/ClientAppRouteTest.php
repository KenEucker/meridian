<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClientAppRouteTest extends TestCase
{
    private ?string $clientDistPath = null;

    protected function tearDown(): void
    {
        if ($this->clientDistPath !== null) {
            File::deleteDirectory($this->clientDistPath);
        }

        parent::tearDown();
    }

    public function test_root_serves_the_shared_vue_client_index(): void
    {
        $this->installClientDist('<!doctype html><div id="app">Shared Vue client</div>');

        $response = $this->get('/');

        $response->assertOk();
        $this->assertFileResponseContains($response, 'Shared Vue client');
    }

    public function test_default_client_dist_path_points_to_meridian_admin_artifact(): void
    {
        $this->assertSame(base_path('../client/dist/admin'), config('meridian.client.dist_path'));
    }

    public function test_client_side_routes_fall_back_to_the_shared_vue_index(): void
    {
        $this->installClientDist('<!doctype html><div id="app">Shared Vue route shell</div>');

        $response = $this->get('/staff/field-reports');

        $response->assertOk();
        $this->assertFileResponseContains($response, 'Shared Vue route shell');
    }

    public function test_local_development_serves_the_vite_client_shell(): void
    {
        config()->set('meridian.client.use_dev_server', true);
        config()->set('meridian.client.dev_server_url', 'http://localhost:5173/');

        $response = $this->get('/staff/field-reports');

        $response->assertOk();
        $response->assertSee('<title>Meridian Admin</title>', false);
        $response->assertSee('http://localhost:5173/@vite/client', false);
        $response->assertSee(
            'window.__MERIDIAN_RUNTIME_CONFIG__ = {"apiBaseUrl":"http://localhost","deploymentTarget":"server","uiMode":"admin"}',
            false,
        );
        $response->assertSee('http://localhost:5173/src/main.ts', false);
    }

    public function test_public_apply_like_routes_do_not_fall_back_to_the_client_app(): void
    {
        $this->installClientDist('<!doctype html><div id="app">Shared Vue route shell</div>');

        $this->get('/summer-fest/apply')->assertNotFound();
    }

    public function test_client_assets_are_served_from_the_shared_vue_build(): void
    {
        $this->installClientDist('<!doctype html><div id="app"></div>');
        File::ensureDirectoryExists($this->clientDistPath.'/assets');
        File::put($this->clientDistPath.'/assets/app.js', "console.log('client');");

        $response = $this->get('/assets/app.js');

        $response->assertOk();
        $this->assertFileResponseContains($response, "console.log('client');");
    }

    public function test_local_development_redirects_client_assets_to_vite(): void
    {
        config()->set('meridian.client.use_dev_server', true);
        config()->set('meridian.client.dev_server_url', 'http://localhost:5173/');

        $this->get('/assets/app.js')->assertRedirect('http://localhost:5173/assets/app.js');
    }

    public function test_client_asset_route_rejects_path_traversal(): void
    {
        $this->installClientDist('<!doctype html><div id="app"></div>');

        $this->get('/assets/../package.json')->assertNotFound();
    }

    private function installClientDist(string $indexHtml): void
    {
        $this->clientDistPath = sys_get_temp_dir().'/meridian-client-'.Str::uuid();
        File::ensureDirectoryExists($this->clientDistPath);
        File::put($this->clientDistPath.'/index.html', $indexHtml);
        config()->set('meridian.client.dist_path', $this->clientDistPath);
    }

    private function assertFileResponseContains(TestResponse $response, string $expected): void
    {
        $file = $response->baseResponse->getFile();

        $this->assertStringContainsString($expected, (string) file_get_contents($file->getPathname()));
    }
}
