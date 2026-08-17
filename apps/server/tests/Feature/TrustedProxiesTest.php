<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Forwarded-scheme trust behind a reverse proxy the deployment does not own
 * (M19.27; technical spec 8.2; deploy/runtipi/README.md).
 *
 * The property under test is double-sided. With `meridian.trusted_proxies`
 * empty — the default — a forwarded scheme header changes nothing, so every
 * existing deployment behaves exactly as before and a client on the open
 * internet cannot talk a node into believing it is secure. Configured, the
 * header is believed from the named proxies and from nobody else, which is
 * what keeps Laravel from generating http:// URLs on an https:// site behind
 * a TLS-terminating platform proxy.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_is_empty_so_no_proxy_is_trusted(): void
    {
        $this->assertSame('', config('meridian.trusted_proxies'));
    }

    public function test_a_forwarded_scheme_is_ignored_by_default(): void
    {
        $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertOk();

        $this->assertFalse($this->app['request']->isSecure());
        $this->assertStringStartsWith('http://', url('/'));
    }

    public function test_a_forwarded_scheme_is_believed_when_the_caller_is_trusted_wholesale(): void
    {
        config(['meridian.trusted_proxies' => '*']);

        $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertOk();

        $this->assertTrue($this->app['request']->isSecure());
        $this->assertStringStartsWith('https://', url('/'));
    }

    public function test_a_forwarded_scheme_is_believed_from_a_listed_proxy(): void
    {
        config(['meridian.trusted_proxies' => '192.168.1.1, 10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->get('/up', ['X-Forwarded-Proto' => 'https'])
            ->assertOk();

        $this->assertTrue($this->app['request']->isSecure());
        $this->assertStringStartsWith('https://', url('/'));
    }

    // A separate test on purpose: the harness builds each request URI through
    // the URL generator, which follows the previous request's scheme, so a
    // secure request followed by this one in one test would hand the second
    // request an https:// URI before the middleware ever saw it.
    public function test_a_forwarded_scheme_is_ignored_from_an_unlisted_caller(): void
    {
        config(['meridian.trusted_proxies' => '192.168.1.1, 10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/up', ['X-Forwarded-Proto' => 'https'])
            ->assertOk();

        $this->assertFalse($this->app['request']->isSecure());
        $this->assertStringStartsWith('http://', url('/'));
    }
}
