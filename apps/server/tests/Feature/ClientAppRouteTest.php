<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientAppController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClientAppRouteTest extends TestCase
{
    private ?string $clientDistPath = null;

    /** @var resource|null */
    private $devServerSocket = null;

    protected function tearDown(): void
    {
        if ($this->clientDistPath !== null) {
            File::deleteDirectory($this->clientDistPath);
        }

        if (is_resource($this->devServerSocket)) {
            fclose($this->devServerSocket);
            $this->devServerSocket = null;
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
        $this->runDevServerMode();
        $devServerUrl = rtrim((string) config('meridian.client.dev_server_url'), '/');

        $response = $this->get('/staff/field-reports');

        $response->assertOk();
        $response->assertHeaderMissing(ClientAppController::CLIENT_SOURCE_HEADER);
        $response->assertSee('<title>Meridian Admin</title>', false);
        $response->assertSee($devServerUrl.'/@vite/client', false);
        $this->assertSame(1, preg_match(
            '#window\.__MERIDIAN_RUNTIME_CONFIG__ = (?P<runtimeConfig>\{[^<]+\});</script>#',
            (string) $response->getContent(),
            $matches,
        ));
        $runtimeConfig = json_decode($matches['runtimeConfig'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('http', parse_url((string) $runtimeConfig['apiBaseUrl'], PHP_URL_SCHEME));
        $this->assertContains(
            parse_url((string) $runtimeConfig['apiBaseUrl'], PHP_URL_HOST),
            ['localhost', '127.0.0.1', '::1'],
        );
        $this->assertSame('server', $runtimeConfig['deploymentTarget']);
        $this->assertSame('admin', $runtimeConfig['uiMode']);
        $response->assertSee($devServerUrl.'/src/main.ts', false);
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

    public function test_client_public_brand_assets_are_served_from_admin_build(): void
    {
        $this->installClientDist('<!doctype html><div id="app"></div>');
        File::ensureDirectoryExists($this->clientDistPath.'/assets/brand');
        File::put($this->clientDistPath.'/assets/brand/meridian-signal-camp-wordmark.webp', 'wordmark');

        $response = $this->get('/assets/brand/meridian-signal-camp-wordmark.webp');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertFileResponseContains($response, 'wordmark');
    }

    public function test_local_development_redirects_client_assets_to_vite(): void
    {
        $this->runDevServerMode();
        $devServerUrl = rtrim((string) config('meridian.client.dev_server_url'), '/');

        $this->get('/assets/app.js')->assertRedirect($devServerUrl.'/assets/app.js');
    }

    /*
     * Dev-server mode with no dev server behind it (the blank-page report).
     *
     * The flag records what a developer means to run, so a node carrying it
     * with nothing on the other end used to serve a shell of two dead module
     * tags: HTTP 200, and a page that could only render blank.
     */

    public function test_dev_server_mode_falls_back_to_the_build_when_no_dev_server_answers(): void
    {
        $this->installClientDist('<!doctype html><html><head></head><body><div id="app">Built client</div></body></html>');
        $this->runDevServerModeWithNoDevServer();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader(ClientAppController::CLIENT_SOURCE_HEADER, 'build-fallback');
        $response->assertSee('Built client', false);
        $response->assertDontSee('@vite/client', false);
    }

    public function test_the_asset_route_falls_back_with_the_page_it_belongs_to(): void
    {
        // The half that makes the fallback usable rather than merely present:
        // a built page whose assets still redirected to an absent dev server
        // would render exactly as blank as the shell it replaced.
        $this->installClientDist('<!doctype html><html><head></head><body></body></html>');
        File::ensureDirectoryExists($this->clientDistPath.'/assets');
        File::put($this->clientDistPath.'/assets/app.js', "console.log('built');");
        $this->runDevServerModeWithNoDevServer();

        $response = $this->get('/assets/app.js');

        $response->assertOk();
        $response->assertHeader(ClientAppController::CLIENT_SOURCE_HEADER, 'build-fallback');
        $this->assertFileResponseContains($response, "console.log('built');");
    }

    public function test_dev_server_mode_with_no_dev_server_and_no_build_still_explains_itself(): void
    {
        $this->runDevServerModeWithNoDevServer();
        config()->set('meridian.client.dist_path', sys_get_temp_dir().'/meridian-client-absent-'.Str::uuid());

        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader(ClientAppController::CLIENT_SOURCE_HEADER, 'build-fallback');
        $response->assertSee('client');
    }

    public function test_serving_the_build_outside_dev_server_mode_is_not_marked_a_fallback(): void
    {
        $this->installClientDist('<!doctype html><html><head></head><body><div id="app"></div></body></html>');
        config()->set('meridian.client.use_dev_server', false);

        $this->get('/')->assertOk()->assertHeaderMissing(ClientAppController::CLIENT_SOURCE_HEADER);
    }

    /*
     * The built client is told which node served it.
     *
     * `nodeConnection.ts` ranks the serving node above the URL baked in at
     * build time and expects the server to inject it. Nothing did, so a build
     * reached by any name other than the one it was built against called
     * across origins and was refused by CORS.
     */

    public function test_the_built_client_is_told_the_origin_that_served_it(): void
    {
        $this->installClientDist('<!doctype html><html><head><title>Meridian</title></head><body><div id="app"></div></body></html>');
        config()->set('meridian.client.use_dev_server', false);

        $response = $this->get('http://meridian.test/staff/dashboard');

        $response->assertOk();
        $this->assertSame(1, preg_match(
            '#window\.__MERIDIAN_RUNTIME_CONFIG__ = (?P<runtimeConfig>\{[^<]+\});</script>#',
            (string) $response->getContent(),
            $matches,
        ));

        $runtimeConfig = json_decode($matches['runtimeConfig'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('http://meridian.test', $runtimeConfig['apiBaseUrl']);

        // The origin and nothing else: which client this is belongs to the
        // build, and a server that also declared the mode could relabel an
        // artifact it does not identify.
        $this->assertSame(['apiBaseUrl'], array_keys($runtimeConfig));
    }

    public function test_the_injected_origin_follows_the_host_the_client_was_reached_by(): void
    {
        $this->installClientDist('<!doctype html><html><head></head><body><div id="app"></div></body></html>');
        config()->set('meridian.client.use_dev_server', false);

        foreach (['http://localhost', 'http://northwood.localhost'] as $origin) {
            $this->get($origin.'/')
                ->assertOk()
                ->assertSee('"apiBaseUrl":"'.$origin.'"', false);
        }
    }

    public function test_the_runtime_config_runs_before_the_module_that_reads_it(): void
    {
        $this->installClientDist(
            '<!doctype html><html><head><script type="module" crossorigin src="/assets/app.js"></script></head>'
            .'<body><div id="app"></div></body></html>',
        );
        config()->set('meridian.client.use_dev_server', false);

        $content = (string) $this->get('/')->assertOk()->getContent();

        $injected = strpos($content, '__MERIDIAN_RUNTIME_CONFIG__');
        $headEnd = strpos($content, '</head>');

        $this->assertNotFalse($injected);
        $this->assertNotFalse($headEnd);

        // A classic inline script anywhere in the document beats a deferred
        // module, but landing it inside <head> keeps that true of a build that
        // moves its module tag into <body>.
        $this->assertLessThan($headEnd, $injected);
        $this->assertStringNotContainsString('type="module"', substr(
            $content,
            (int) strrpos(substr($content, 0, $injected), '<script'),
            $injected,
        ));
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

    /**
     * Put dev-server mode behind something that actually answers.
     *
     * A real listening socket rather than a fixed port, because the controller
     * now confirms the dev server before it trusts the flag: a test naming
     * 5173 would assert the dev-server path only on a machine that happened to
     * be running Vite, and assert the fallback everywhere else.
     */
    private function runDevServerMode(): void
    {
        $this->devServerSocket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        $this->assertIsResource($this->devServerSocket, 'Could not open a stand-in dev server socket.');

        $address = stream_socket_get_name($this->devServerSocket, false);

        config()->set('meridian.client.use_dev_server', true);
        config()->set('meridian.client.dev_server_url', 'http://'.$address.'/');
    }

    /**
     * Dev-server mode configured with nothing behind it, which is a developer
     * running the server and no Vite.
     */
    private function runDevServerModeWithNoDevServer(): void
    {
        // Bind and release, so the port is one nothing is listening on rather
        // than one this test hopes is free.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        config()->set('meridian.client.use_dev_server', true);
        config()->set('meridian.client.dev_server_url', 'http://'.$address.'/');
    }

    private function assertFileResponseContains(TestResponse $response, string $expected): void
    {
        if (! method_exists($response->baseResponse, 'getFile')) {
            $this->assertStringContainsString($expected, (string) $response->getContent());

            return;
        }

        $file = $response->baseResponse->getFile();

        $this->assertStringContainsString($expected, (string) file_get_contents($file->getPathname()));
    }
}
