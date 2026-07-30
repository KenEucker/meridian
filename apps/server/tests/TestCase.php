<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a Meridian client application acting for this user.
     *
     * `actingAs($user)` establishes a browser session, and no `/api/*` route
     * accepts one: client applications authenticate with a device-bound bearer
     * token (AUTH-018; technical spec 11.4), and `config/sanctum.php` removes
     * the guard fallback that would otherwise let the God Mode console's session
     * through. The `sanctum` guard is what a bearer token resolves to, so an API
     * test using this reaches the same guard a real client does.
     *
     * Tests about issuance, expiry, revocation, or the credential boundary
     * itself present a real token instead, because there the token — not the
     * user behind it — is the thing under test.
     */
    protected function actingAsClient(Authenticatable $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
