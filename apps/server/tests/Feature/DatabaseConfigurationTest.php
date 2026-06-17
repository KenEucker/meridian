<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_default_connection_falls_back_to_postgresql(): void
    {
        $config = file_get_contents(config_path('database.php'));

        $this->assertStringContainsString(
            "env('DB_CONNECTION', 'pgsql')",
            $config,
            'The default database connection should fall back to PostgreSQL.'
        );
    }

    public function test_pgsql_connection_uses_meridian_development_defaults(): void
    {
        $config = file_get_contents(config_path('database.php'));

        $this->assertStringContainsString("env('DB_DATABASE', 'meridian')", $config);
        $this->assertStringContainsString("env('DB_USERNAME', 'meridian')", $config);

        $this->assertSame('pgsql', config('database.connections.pgsql.driver'));
        $this->assertSame('public', config('database.connections.pgsql.search_path'));
    }

    public function test_env_example_documents_postgresql_for_local_development(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^DB_CONNECTION=pgsql$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_HOST=127\.0\.0\.1$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_PORT=5432$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=meridian$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_USERNAME=meridian$/m', $env);
    }
}
