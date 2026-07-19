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

    public function test_client_build_missing_page_links_the_shared_tokens_stylesheet(): void
    {
        config()->set('meridian.client.dist_path', sys_get_temp_dir().'/missing-meridian-client-dist');

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
