<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\SystemConfigOverride;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticsExport;
use App\Services\SystemConfig\SystemConfigOverrideStore;
use App\Services\SystemConfig\SystemConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Secret handling and authorization for system configuration (SYS-013
 * through SYS-015, SYS-024 through SYS-027, SYS-034, SYS-035).
 */
class SystemConfigSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'super-secret-mail-password-84121';

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = Node::factory()->create();
    }

    private function store(): SystemConfigOverrideStore
    {
        return app(SystemConfigOverrideStore::class);
    }

    private function putSecret(): SystemConfigOverride
    {
        return $this->store()->put($this->node, 'MAIL_PASSWORD', self::SECRET, null, 'rotate the mail credential');
    }

    public function test_secrets_are_encrypted_at_rest(): void
    {
        $override = $this->putSecret();

        $raw = DB::table('system_config_overrides')->where('id', $override->getKey())->value('secret_value');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::SECRET, (string) $raw);
        $this->assertNull($override->value_json);
        $this->assertSame(self::SECRET, $override->fresh()->secret_value);
    }

    public function test_secrets_require_a_change_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->put($this->node, 'MAIL_PASSWORD', self::SECRET, null, null);
    }

    public function test_secrets_are_masked_in_the_resolver(): void
    {
        $this->putSecret();

        $resolved = app(SystemConfigResolver::class)->valueFor($this->node, 'MAIL_PASSWORD');

        $this->assertStringNotContainsString(self::SECRET, $resolved->displayValue);
        $this->assertStringNotContainsString(self::SECRET, (string) $resolved->overrideDisplayValue);
    }

    public function test_secrets_never_reach_the_audit_log_in_plaintext(): void
    {
        $this->putSecret();
        $this->store()->put($this->node, 'MAIL_PASSWORD', self::SECRET.'-2', null, 'rotate again');
        $this->store()->remove($this->node, 'MAIL_PASSWORD', null, 'remove it');

        foreach (AuditEvent::query()->get() as $event) {
            $serialized = json_encode([
                $event->before_json,
                $event->after_json,
                $event->reason,
            ]);

            $this->assertStringNotContainsString(self::SECRET, (string) $serialized);
        }

        $this->assertGreaterThanOrEqual(3, AuditEvent::query()->count());
    }

    public function test_secrets_never_reach_the_log_channel(): void
    {
        Log::spy();

        $this->putSecret();

        // Saving a secret logs nothing at all, and the skip/failure paths log
        // names and reasons only — never values. The applier's own messages
        // are covered by the precedence tests; here we assert the write path
        // stayed silent.
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
    }

    public function test_secrets_never_reach_the_diagnostics_export(): void
    {
        $this->putSecret();

        $bundle = json_encode(app(DiagnosticsExport::class)->build(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SECRET, $bundle);
        // Non-secret values also export as source/status only (SYS-035): the
        // configuration section carries no `value` field at all.
        $decoded = json_decode($bundle, true);

        foreach ($decoded['configuration'] as $entry) {
            $this->assertArrayNotHasKey('value', $entry);
            $this->assertArrayNotHasKey('display_value', $entry);
        }
    }

    public function test_the_configuration_screens_mask_secrets(): void
    {
        $this->putSecret();

        $response = $this->actingAs($this->operator())->get(
            route('platform.system.configuration.edit', ['variable' => 'MAIL_PASSWORD']),
        );

        $response->assertOk();
        $response->assertDontSee(self::SECRET);
    }

    public function test_unauthorized_users_cannot_reach_configuration_or_diagnostics(): void
    {
        // A console user with no system permissions: organization-level roles
        // never imply infrastructure administration (SYS-025).
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->get(route('platform.system.configuration'))->assertForbidden();
        $this->actingAs($user)->get(route('platform.system.diagnostics'))->assertForbidden();
        $this->actingAs($user)->get(route('platform.system.node-health'))->assertForbidden();
    }

    public function test_viewing_does_not_grant_managing(): void
    {
        $viewer = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.system.configuration' => true,
        ]]);

        $this->actingAs($viewer)
            ->post($this->screenMethodUrl('APP_DEBUG', 'save'), [
                'override' => ['value' => 'false', 'reason' => 'no'],
            ])
            ->assertForbidden();

        $this->assertSame(0, SystemConfigOverride::query()->count());
    }

    public function test_changing_a_secret_requires_the_secret_permission(): void
    {
        $manager = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.system.configuration' => true,
            'platform.system.configuration.manage' => true,
        ]]);

        $this->actingAs($manager)
            ->post($this->screenMethodUrl('MAIL_PASSWORD', 'save'), [
                'override' => ['value' => self::SECRET, 'reason' => 'rotate'],
            ])
            ->assertForbidden();

        $this->assertSame(0, SystemConfigOverride::query()->count());
    }

    public function test_a_secret_manager_can_replace_but_never_read_a_secret(): void
    {
        $this->putSecret();

        $response = $this->actingAs($this->operator())
            ->post($this->screenMethodUrl('MAIL_PASSWORD', 'save'), [
                'override' => ['value' => 'replacement-secret-6821', 'reason' => 'rotate'],
            ]);

        $response->assertRedirect();

        $override = SystemConfigOverride::query()->where('name', 'MAIL_PASSWORD')->firstOrFail();
        $this->assertSame('replacement-secret-6821', $override->secret_value);

        // And the screen still shows only the mask afterwards.
        $view = $this->actingAs($this->operator())->get(
            route('platform.system.configuration.edit', ['variable' => 'MAIL_PASSWORD']),
        );
        $view->assertDontSee('replacement-secret-6821');
    }

    private function operator(): User
    {
        return User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.system.configuration' => true,
            'platform.system.configuration.manage' => true,
            'platform.system.secrets' => true,
            'platform.system.configuration.audit' => true,
            'platform.system.diagnostics' => true,
            'platform.system.diagnostics.export' => true,
        ]]);
    }

    private function screenMethodUrl(string $variable, string $method): string
    {
        return route('platform.system.configuration.edit', ['variable' => $variable]).'/'.$method;
    }
}
