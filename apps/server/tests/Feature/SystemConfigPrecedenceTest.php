<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\SystemConfigOverride;
use App\Services\SystemConfig\ApplySystemConfigOverrides;
use App\Services\SystemConfig\ResolvedConfigValue;
use App\Services\SystemConfig\SystemConfigOverrideStore;
use App\Services\SystemConfig\SystemConfigResolver;
use App\Services\SystemConfig\SystemConfigValueCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Override precedence and boot-time application (technical spec 22A.6;
 * SYS-005 through SYS-010, SYS-022).
 */
class SystemConfigPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = Node::factory()->create();
    }

    private function applier(): ApplySystemConfigOverrides
    {
        $applier = app(ApplySystemConfigOverrides::class);
        $applier->fresh();

        return $applier;
    }

    private function store(): SystemConfigOverrideStore
    {
        return app(SystemConfigOverrideStore::class);
    }

    public function test_a_database_override_wins_over_environment_derived_config(): void
    {
        // BCRYPT_ROUNDS is pinned to 4 by phpunit.xml, so the environment
        // value is deterministic here.
        $this->assertSame(4, (int) config('hashing.bcrypt.rounds'));

        $this->store()->put($this->node, 'BCRYPT_ROUNDS', '6', null, 'test');

        $applier = $this->applier();
        $applier->apply();

        $this->assertSame(6, config('hashing.bcrypt.rounds'));
        $this->assertContains('BCRYPT_ROUNDS', $applier->appliedNames());

        $resolved = app(SystemConfigResolver::class)->valueFor($this->node, 'BCRYPT_ROUNDS');
        $this->assertSame(ResolvedConfigValue::SOURCE_DATABASE_OVERRIDE, $resolved->source);
    }

    public function test_removing_an_override_restores_the_underlying_value(): void
    {
        $this->store()->put($this->node, 'BCRYPT_ROUNDS', '6', null, 'test');
        $this->store()->remove($this->node, 'BCRYPT_ROUNDS', null, 'test');

        config()->set('hashing.bcrypt.rounds', 4);

        $applier = $this->applier();
        $applier->apply();

        $this->assertSame(4, config('hashing.bcrypt.rounds'));
        $this->assertSame([], $applier->appliedNames());

        $resolved = app(SystemConfigResolver::class)->valueFor($this->node, 'BCRYPT_ROUNDS');
        $this->assertSame(ResolvedConfigValue::SOURCE_ENVIRONMENT, $resolved->source);
        $this->assertSame('Environment / .env', $resolved->sourceLabel());
    }

    public function test_a_disabled_override_is_ignored(): void
    {
        $this->store()->put($this->node, 'BCRYPT_ROUNDS', '6', null, 'test');
        $this->store()->setActive($this->node, 'BCRYPT_ROUNDS', false, null, 'test');

        config()->set('hashing.bcrypt.rounds', 4);

        $applier = $this->applier();
        $applier->apply();

        $this->assertSame(4, config('hashing.bcrypt.rounds'));
    }

    public function test_an_invalid_override_is_skipped_and_reported(): void
    {
        SystemConfigOverride::factory()->create([
            'node_id' => $this->node->getKey(),
            'name' => 'BCRYPT_ROUNDS',
            'type' => 'integer',
            'value_json' => json_encode('not-a-number'),
        ]);

        config()->set('hashing.bcrypt.rounds', 4);

        $applier = $this->applier();
        $applier->apply();

        $this->assertSame(4, config('hashing.bcrypt.rounds'));
        $this->assertSame(['BCRYPT_ROUNDS'], array_column($applier->skipped(), 'name'));

        $resolved = app(SystemConfigResolver::class)->valueFor($this->node, 'BCRYPT_ROUNDS');
        $this->assertSame(ResolvedConfigValue::SOURCE_INVALID, $resolved->source);
        $this->assertNotNull($resolved->validationError);
    }

    public function test_a_bootstrap_locked_variable_is_never_applied_even_if_a_row_exists(): void
    {
        SystemConfigOverride::factory()->create([
            'node_id' => $this->node->getKey(),
            'name' => 'DB_HOST',
            'type' => 'string',
            'value_json' => json_encode('evil-host'),
        ]);

        $before = config('database.connections.pgsql.host');

        $applier = $this->applier();
        $applier->apply();

        $this->assertSame($before, config('database.connections.pgsql.host'));
        $this->assertSame(['DB_HOST'], array_column($applier->skipped(), 'name'));
    }

    public function test_typed_values_keep_laravel_semantics(): void
    {
        $this->store()->put($this->node, 'APP_DEBUG', 'false', null, null);
        $this->store()->put($this->node, 'MAIL_PORT', '0', null, null);
        $this->store()->put($this->node, 'APP_NAME', '0', null, null);

        $applier = $this->applier();
        $applier->apply();

        $this->assertFalse(config('app.debug'));
        $this->assertSame(0, config('mail.mailers.smtp.port'));
        $this->assertSame('0', config('app.name'));
    }

    public function test_storage_encoding_keeps_type_distinctions(): void
    {
        $codec = app(SystemConfigValueCodec::class);

        $values = [null, '', false, 0, '0', []];
        $encoded = array_map(fn ($value) => $codec->encode($value), $values);

        $this->assertSame(count($values), count(array_unique($encoded)), 'encodings must stay distinct');

        foreach ($values as $index => $value) {
            $this->assertSame($value, $codec->decode($encoded[$index]));
        }
    }

    public function test_an_unreadable_override_table_falls_back_to_environment_configuration(): void
    {
        config()->set('hashing.bcrypt.rounds', 4);

        Schema::shouldReceive('hasTable')
            ->with('system_config_overrides')
            ->andThrow(new \RuntimeException('SQLSTATE[08006] connection refused'));

        $applier = $this->applier();
        $applier->apply();

        $this->assertTrue($applier->loadFailed());
        $this->assertSame(\RuntimeException::class, $applier->loadFailureReason());
        $this->assertSame(4, config('hashing.bcrypt.rounds'));
    }

    public function test_overrides_only_apply_from_the_local_active_node(): void
    {
        $remote = Node::factory()->remote()->create();

        SystemConfigOverride::factory()->create([
            'node_id' => $remote->getKey(),
            'name' => 'BCRYPT_ROUNDS',
            'type' => 'integer',
            'value_json' => json_encode(9),
        ]);

        config()->set('hashing.bcrypt.rounds', 4);

        $applier = $this->applier();
        $applier->apply();

        $this->assertSame(4, config('hashing.bcrypt.rounds'));
    }
}
