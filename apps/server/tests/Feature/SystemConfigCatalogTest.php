<?php

namespace Tests\Feature;

use App\Services\SystemConfig\CatalogEntry;
use App\Services\SystemConfig\EnvExampleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `.env.example` configuration catalogue: parsing, sections, metadata
 * tags, config-key mappings, and bootstrap locks (technical spec 22A.2;
 * SYS-001 through SYS-004).
 */
class SystemConfigCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): EnvExampleCatalog
    {
        return app(EnvExampleCatalog::class);
    }

    public function test_the_catalogue_parses_the_real_env_example(): void
    {
        $entries = $this->catalog()->entries();

        $this->assertNotEmpty($entries);
        $this->assertContains('Application', $this->catalog()->sections());
        $this->assertContains('Database', $this->catalog()->sections());
    }

    public function test_sections_come_from_headings_and_descriptions_from_comments(): void
    {
        $entry = $this->catalog()->entry('APP_KEY');

        $this->assertNotNull($entry);
        $this->assertSame('Application', $entry->section);
        $this->assertStringContainsString('encryption key', $entry->description);
        $this->assertSame('Application key', $entry->label);
    }

    public function test_bootstrap_locked_variables_are_never_editable(): void
    {
        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_HOST', 'CACHE_STORE', 'SESSION_DRIVER', 'MERIDIAN_NODE_PRIVATE_KEY'] as $name) {
            $entry = $this->catalog()->entry($name);

            $this->assertNotNull($entry, $name);
            $this->assertTrue($entry->bootstrapLocked, "{$name} should be bootstrap-locked");
            $this->assertFalse($entry->editable(), "{$name} should not be editable");
            $this->assertSame(CatalogEntry::ACTIVATION_BOOTSTRAP, $entry->activation());
        }
    }

    public function test_secret_variables_are_marked_secret(): void
    {
        foreach ([
            'APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'REDIS_PASSWORD',
            'GOOGLE_OAUTH_CLIENT_SECRET', 'DISCORD_OAUTH_CLIENT_SECRET', 'MERIDIAN_LOCAL_FIELD_API_TOKEN',
            'MERIDIAN_CHANGELOG_TOKEN', 'MERIDIAN_NODE_PRIVATE_KEY',
        ] as $name) {
            $this->assertTrue($this->catalog()->entry($name)?->secret, "{$name} should be secret");
        }
    }

    public function test_managed_node_identity_variables_are_not_editable_here(): void
    {
        $entry = $this->catalog()->entry('MERIDIAN_NODE_NAME');

        $this->assertNotNull($entry);
        $this->assertSame('Node Configuration', $entry->managedLabel);
        $this->assertFalse($entry->editable());
    }

    public function test_unmapped_variables_are_read_only(): void
    {
        $entry = $this->catalog()->entry('MEMCACHED_HOST');

        $this->assertNotNull($entry);
        $this->assertFalse($entry->mapped());
        $this->assertFalse($entry->editable());
    }

    public function test_commented_out_variables_are_still_catalogued(): void
    {
        $entry = $this->catalog()->entry('DB_SSLMODE');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->commentedOut);
        $this->assertTrue($entry->bootstrapLocked);
    }

    public function test_enum_types_carry_their_values(): void
    {
        $entry = $this->catalog()->entry('MERIDIAN_NODE_ROLE');

        $this->assertNotNull($entry);
        $this->assertSame(CatalogEntry::TYPE_ENUM, $entry->type);
        $this->assertSame(['development', 'standalone', 'central', 'onsite'], $entry->enumValues);
    }

    public function test_every_mapped_config_key_exists_in_the_config_repository(): void
    {
        foreach ($this->catalog()->entries() as $entry) {
            foreach ($entry->configKeys as $key) {
                $this->assertTrue(
                    config()->has($key),
                    "{$entry->name} maps to config key {$key}, which does not exist",
                );
            }
        }
    }

    public function test_activation_classes_resolve_from_restart_tags(): void
    {
        $this->assertSame(CatalogEntry::ACTIVATION_WORKERS, $this->catalog()->entry('QUEUE_CONNECTION')?->activation());
        $this->assertSame(CatalogEntry::ACTIVATION_REQUEST, $this->catalog()->entry('APP_DEBUG')?->activation());
        $this->assertSame(CatalogEntry::ACTIVATION_DEPLOY, $this->catalog()->entry('PHP_CLI_SERVER_WORKERS')?->activation());
    }

    public function test_a_variable_without_declared_type_stays_a_string(): void
    {
        $parsed = $this->catalog()->parse("## Test\nSOME_VAR=abc\n");

        $this->assertCount(1, $parsed);
        $this->assertSame(CatalogEntry::TYPE_STRING, $parsed[0]->type);
        $this->assertSame('Test', $parsed[0]->section);
        $this->assertFalse($parsed[0]->editable());
    }
}
