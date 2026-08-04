<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The database is refreshed because the deployment root reads the node
     * record since M18.23: whether it serves the public marketing surface or
     * the client application depends on the node's role and event lock
     * (PUBLIC-006). Before that it was a static file read and needed no
     * schema.
     */
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
