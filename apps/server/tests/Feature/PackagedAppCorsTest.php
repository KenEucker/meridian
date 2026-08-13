<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The packaged mobile apps are cross-origin callers (technical spec 3.3, 26.5).
 *
 * A Capacitor app serves its bundled client from a fixed local origin rather
 * than from the node, so every call it makes is cross-origin exactly as the dev
 * server's is — and an origin the node does not allow is refused by the browser
 * before the response is read. On a phone that reads as a node that cannot be
 * reached at all, with nothing in the node's own logs to say otherwise, which
 * is why this is asserted rather than left to the deployment to discover.
 *
 * The origin grants nothing on its own: requests still carry a device-bound
 * bearer token (AUTH-018), and `/api/health` is the unauthenticated probe every
 * client uses to decide whether a node is there.
 */
final class PackagedAppCorsTest extends TestCase
{
    public static function packagedAppOrigins(): array
    {
        return [
            'Android' => ['https://localhost'],
            'iOS' => ['capacitor://localhost'],
        ];
    }

    #[DataProvider('packagedAppOrigins')]
    public function test_a_packaged_app_may_call_a_node_from_its_own_origin(string $origin): void
    {
        $response = $this->withHeader('Origin', $origin)->getJson('/api/health');

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    /**
     * The list is an allowlist, and stays one. A node that echoed any origin
     * would be answering for callers nobody configured.
     */
    public function test_an_unlisted_origin_is_not_allowed(): void
    {
        $response = $this->withHeader('Origin', 'https://not-a-meridian-client.example')
            ->getJson('/api/health');

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    /**
     * The shared client's dev server keeps working; the packaged origins were
     * added beside it rather than in place of it.
     */
    public function test_the_client_dev_server_is_still_allowed(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/health');

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
