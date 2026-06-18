<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedUiTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_tokens_asset_is_served_with_light_and_dark_values(): void
    {
        $path = public_path('css/meridian-tokens.css');

        $this->assertFileExists($path);

        $css = file_get_contents($path);

        $this->assertStringContainsString('--m-surface-app', $css);
        $this->assertStringContainsString('--m-text-primary', $css);
        $this->assertStringContainsString('--m-action-primary-bg', $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css);
    }

    public function test_welcome_page_links_the_shared_tokens_stylesheet(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('css/meridian-tokens.css', false);
    }

    public function test_orchid_admin_registers_the_shared_tokens_stylesheet(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('css/meridian-tokens.css', false);
    }
}
