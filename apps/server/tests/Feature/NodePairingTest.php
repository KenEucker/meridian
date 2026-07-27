<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\NodePairingToken;
use App\Models\User;
use App\Services\Node\NodePairingClient;
use App\Services\Node\NodePairingException;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodePairingTokenGenerator;
use App\Services\Node\NodePairingTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * M12.1 node pairing token: an on-site node pairs with central using a
 * one-time token created by central (technical spec 7.3, 7.4).
 */
class NodePairingTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    /**
     * Node ids are global, so a node keeps one id across every install that
     * knows it (technical spec 10.4). Each node name in these tests therefore
     * has one stable id, the way a real install would.
     *
     * @var array<string, string>
     */
    private array $nodeIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Pairing itself is covered here, not the M8.7 event-mode fail-closed
        // safeguards; those are covered by EventModeSetupFailClosedTest. Tests
        // that need event mode turn it on explicitly.
        config(['meridian.event_mode.enabled' => false]);
    }

    // Token issue on central

    public function test_central_node_issues_a_one_time_token_and_stores_only_its_hash(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        $user = $this->godModeUser();

        $issued = app(NodePairingTokenService::class)->issue(issuedBy: $user);

        $this->assertStringStartsWith(NodePairingTokenGenerator::PREFIX, $issued->plaintext);
        $this->assertSame(
            NodePairingTokenGenerator::hash($issued->plaintext),
            $issued->token->token_hash,
        );
        $this->assertDatabaseMissing('node_pairing_tokens', [
            'token_hash' => $issued->plaintext,
        ]);
        $this->assertSame($central->id, $issued->token->issued_by_node_id);
        $this->assertSame($user->id, $issued->token->issued_by_user_id);
        $this->assertTrue($issued->token->isActive());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'node_pairing_token.issued',
            'entity_id' => $issued->token->id,
            'actor_user_id' => $user->id,
            'actor_node_id' => $central->id,
        ]);
    }

    public function test_a_node_that_is_not_central_cannot_issue_a_pairing_token(): void
    {
        $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');

        $this->expectException(NodePairingException::class);

        try {
            app(NodePairingTokenService::class)->issue();
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_NOT_A_CENTRAL_NODE, $exception->reason);
            $this->assertDatabaseCount('node_pairing_tokens', 0);

            throw $exception;
        }
    }

    public function test_issuing_a_token_before_node_setup_is_refused(): void
    {
        $this->expectException(NodePairingException::class);

        try {
            app(NodePairingTokenService::class)->issue();
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_NODE_NOT_CONFIGURED, $exception->reason);

            throw $exception;
        }
    }

    // Token redemption on central

    public function test_onsite_node_pairs_with_central_using_a_valid_token(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [$plaintext, $token] = $this->issuedToken($central);

        $response = $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('central_node.node_name', 'juplaya.central');
        $response->assertJsonPath('central_node.public_key', $central->public_key);
        $response->assertJsonPath('paired_node.node_name', 'juplaya.2027.onsite');
        $response->assertJsonPath('replayed', false);

        $paired = Node::query()->where('node_name', 'juplaya.2027.onsite')->firstOrFail();

        $this->assertFalse($paired->is_local);
        $this->assertSame(Node::ROLE_ONSITE, $paired->node_role);
        $this->assertSame('onsite-public-key', $paired->public_key);
        $this->assertNotNull($paired->paired_at);

        $token->refresh();

        $this->assertTrue($token->isUsed());
        $this->assertSame($paired->id, $token->paired_node_id);
        $this->assertFalse($token->isActive());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'node.paired',
            'entity_id' => $paired->id,
            'actor_node_id' => $central->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);
    }

    public function test_a_used_token_cannot_pair_a_second_node(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [$plaintext] = $this->issuedToken($central);

        $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ])->assertCreated();

        $response = $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('gerlach.2027.onsite'),
            'node_name' => 'gerlach.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'other-public-key',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('reason', NodePairingException::REASON_INVALID_TOKEN);
        $this->assertDatabaseMissing('nodes', ['node_name' => 'gerlach.2027.onsite']);
    }

    public function test_replaying_a_token_with_the_same_node_identity_returns_the_original_pairing(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [$plaintext] = $this->issuedToken($central);

        $body = [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ];

        $first = $this->postJson(route('api.node-pairing.store'), $body)->assertCreated();
        $second = $this->postJson(route('api.node-pairing.store'), $body);

        $second->assertOk();
        $second->assertJsonPath('replayed', true);
        $second->assertJsonPath('paired_node.id', $first->json('paired_node.id'));

        $this->assertSame(1, Node::query()->remote()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'node.paired')->count());
    }

    public function test_unknown_revoked_and_expired_tokens_are_refused(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');

        $revoked = NodePairingTokenGenerator::generate();
        NodePairingToken::factory()
            ->forPlaintext($revoked)
            ->revoked()
            ->create(['issued_by_node_id' => $central->id]);

        $expired = NodePairingTokenGenerator::generate();
        NodePairingToken::factory()
            ->forPlaintext($expired)
            ->expired()
            ->create(['issued_by_node_id' => $central->id]);

        foreach ([NodePairingTokenGenerator::generate(), $revoked, $expired] as $plaintext) {
            $response = $this->postJson(route('api.node-pairing.store'), [
                'pairing_token' => $plaintext,
                'node_id' => $this->nodeId('juplaya.2027.onsite'),
                'node_name' => 'juplaya.2027.onsite',
                'node_role' => Node::ROLE_ONSITE,
                'public_key' => 'onsite-public-key',
            ]);

            $response->assertStatus(401);
            $response->assertJsonPath('reason', NodePairingException::REASON_INVALID_TOKEN);
        }

        $this->assertSame(0, Node::query()->remote()->count());
    }

    public function test_pairing_is_refused_when_this_install_is_not_a_central_node(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');
        [$plaintext] = $this->issuedToken($onsite);

        $response = $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('gerlach.2027.onsite'),
            'node_name' => 'gerlach.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('reason', NodePairingException::REASON_NOT_A_CENTRAL_NODE);
        $this->assertSame(0, Node::query()->remote()->count());
    }

    public function test_pairing_refuses_a_node_name_already_held_with_different_key_material(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [$plaintext, $token] = $this->issuedToken($central);

        Node::factory()->remote()->onsite()->create([
            'node_name' => 'juplaya.2027.onsite',
            'public_key' => 'a-different-public-key',
        ]);

        $response = $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('reason', NodePairingException::REASON_NODE_NAME_CONFLICT);
        $this->assertTrue($token->fresh()->isActive());
    }

    public function test_pairing_refuses_a_revoked_peer_node(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [$plaintext] = $this->issuedToken($central);

        Node::factory()->remote()->onsite()->create([
            'id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'public_key' => 'onsite-public-key',
            'revoked_at' => now(),
        ]);

        $response = $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('reason', NodePairingException::REASON_NODE_REVOKED);
    }

    public function test_pairing_rejects_invalid_input_and_non_pairable_roles(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [$plaintext] = $this->issuedToken($central);

        $this->postJson(route('api.node-pairing.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pairing_token', 'node_name', 'node_role', 'public_key']);

        $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $plaintext,
            'node_name' => 'not a node name',
            'node_role' => Node::ROLE_CENTRAL,
            'public_key' => 'onsite-public-key',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['node_name', 'node_role']);

        $this->assertSame(0, Node::query()->remote()->count());
    }

    // On-site pairing client

    public function test_onsite_node_stores_central_identity_and_pairing_state(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');
        $user = $this->godModeUser();

        $centralPayload = $this->centralPairingResponse();

        Http::fake([
            'central.example.org/api/node-pairing' => Http::response($centralPayload, 201),
        ]);

        $result = app(NodePairingClient::class)->pair(
            plaintextToken: 'mrdn-pair-token',
            centralNodeUrl: 'https://central.example.org/',
            actor: $user,
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://central.example.org/api/node-pairing'
            && $request['pairing_token'] === 'mrdn-pair-token'
            // Node ids are global, so this node tells central the id it already
            // knows itself by (technical spec 10.4).
            && $request['node_id'] === (string) $onsite->getKey()
            && $request['node_name'] === 'juplaya.2027.onsite'
            && $request['node_role'] === Node::ROLE_ONSITE
            && $request['public_key'] === $onsite->public_key);

        $this->assertSame('juplaya.central', $result['central_node']->node_name);

        $peer = Node::query()->where('node_name', 'juplaya.central')->firstOrFail();

        $this->assertFalse($peer->is_local);
        $this->assertSame(Node::ROLE_CENTRAL, $peer->node_role);
        $this->assertSame('central-public-key', $peer->public_key);
        // Central keeps the id it knows itself by, so an operation central
        // signs names an origin node this install can resolve.
        $this->assertSame($centralPayload['central_node']['id'], (string) $peer->getKey());

        $onsite->refresh();

        $this->assertSame('https://central.example.org', $onsite->central_node_url);
        $this->assertNotNull($onsite->paired_at);

        $overrides = NodeConfigValue::query()
            ->where('node_id', $onsite->id)
            ->pluck('value_json', 'key');

        $this->assertSame('https://central.example.org', $overrides[NodePairingState::CONFIG_CENTRAL_NODE_URL]);
        $this->assertSame('juplaya.central', $overrides[NodePairingState::CONFIG_CENTRAL_NODE_NAME]);
        $this->assertSame('central-public-key', $overrides[NodePairingState::CONFIG_CENTRAL_NODE_PUBLIC_KEY]);
        $this->assertSame('https://central.example.org', $overrides[NodePairingState::CONFIG_CENTRAL_PAIRED_URL]);
        $this->assertNotNull($overrides[NodePairingState::CONFIG_CENTRAL_PAIRED_AT]);

        $this->assertSame(
            NodePairingState::STATUS_PAIRED,
            app(NodePairingState::class)->status($onsite->fresh()),
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'node.pairing_completed',
            'entity_id' => $onsite->id,
            'actor_user_id' => $user->id,
            'actor_node_id' => $onsite->id,
        ]);
    }

    public function test_changing_the_central_node_url_requires_a_pairing_recheck(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');
        $state = app(NodePairingState::class);

        $this->assertSame(NodePairingState::STATUS_UNPAIRED, $state->status($onsite));

        Http::fake([
            'central.example.org/api/node-pairing' => Http::response($this->centralPairingResponse(), 201),
        ]);

        app(NodePairingClient::class)->pair(
            plaintextToken: 'mrdn-pair-token',
            centralNodeUrl: 'https://central.example.org',
        );

        $this->assertSame(NodePairingState::STATUS_PAIRED, $state->status($onsite->fresh()));

        // Technical spec 7.3: changing the central node URL triggers a
        // node-pairing recheck.
        $this->screen('platform.node.config')
            ->actingAs($this->godModeUser())
            ->withoutFollowingRedirects()
            ->method('save', [
                'node' => [
                    'node_name' => $onsite->node_name,
                    'node_role' => Node::ROLE_ONSITE,
                    'central_node_url' => 'https://other-central.example.org',
                ],
            ]);

        $this->assertSame(
            NodePairingState::STATUS_RECHECK_REQUIRED,
            $state->status($onsite->fresh()),
        );
    }

    public function test_a_central_or_development_node_does_not_pair_with_central(): void
    {
        $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');

        Http::fake();

        $this->expectException(NodePairingException::class);

        try {
            app(NodePairingClient::class)->pair(
                plaintextToken: 'mrdn-pair-token',
                centralNodeUrl: 'https://central.example.org',
            );
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_ROLE_CANNOT_PAIR, $exception->reason);
            Http::assertNothingSent();

            throw $exception;
        }
    }

    public function test_event_mode_refuses_pairing_over_plain_http(): void
    {
        config(['meridian.event_mode.enabled' => true]);

        $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');

        Http::fake();

        $this->expectException(NodePairingException::class);

        try {
            app(NodePairingClient::class)->pair(
                plaintextToken: 'mrdn-pair-token',
                centralNodeUrl: 'http://central.example.org',
            );
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_INSECURE_CENTRAL_URL, $exception->reason);
            Http::assertNothingSent();

            throw $exception;
        }
    }

    public function test_pairing_without_a_configured_central_url_is_refused(): void
    {
        $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');

        Http::fake();

        $this->expectException(NodePairingException::class);

        try {
            app(NodePairingClient::class)->pair(plaintextToken: 'mrdn-pair-token');
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_CENTRAL_URL_MISSING, $exception->reason);

            throw $exception;
        }
    }

    public function test_an_unreachable_central_node_is_reported_without_recording_a_pairing(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');

        Http::fake(function (): never {
            throw new ConnectionException('Connection timed out.');
        });

        try {
            app(NodePairingClient::class)->pair(
                plaintextToken: 'mrdn-pair-token',
                centralNodeUrl: 'https://central.example.org',
            );
            $this->fail('Pairing should fail when central is unreachable.');
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_CENTRAL_UNREACHABLE, $exception->reason);
        }

        $this->assertSame(0, Node::query()->remote()->count());
        $this->assertSame(
            NodePairingState::STATUS_UNPAIRED,
            app(NodePairingState::class)->status($onsite->fresh()),
        );
    }

    public function test_a_central_refusal_is_surfaced_without_recording_a_pairing(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');

        Http::fake([
            'central.example.org/api/node-pairing' => Http::response([
                'message' => 'The pairing token is not valid.',
                'reason' => NodePairingException::REASON_INVALID_TOKEN,
            ], 401),
        ]);

        try {
            app(NodePairingClient::class)->pair(
                plaintextToken: 'mrdn-pair-token',
                centralNodeUrl: 'https://central.example.org',
            );
            $this->fail('Pairing should fail when central refuses the token.');
        } catch (NodePairingException $exception) {
            $this->assertSame(NodePairingException::REASON_CENTRAL_REFUSED, $exception->reason);
            $this->assertStringContainsString('The pairing token is not valid.', $exception->getMessage());
        }

        $this->assertSame(0, Node::query()->remote()->count());
        $this->assertDatabaseMissing('node_config_values', [
            'node_id' => $onsite->id,
            'key' => NodePairingState::CONFIG_CENTRAL_PAIRED_AT,
        ]);
    }

    // God mode surface

    public function test_god_mode_creates_a_pairing_token_on_a_central_node(): void
    {
        $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        $user = $this->godModeUser();

        $response = $this->screen('platform.node.config')
            ->actingAs($user)
            ->withoutFollowingRedirects()
            ->method('createPairingToken');

        $response->assertRedirect(route('platform.node.config'));
        $response->assertSessionHas('meridian.issued_pairing_token');

        $plaintext = session('meridian.issued_pairing_token');

        $this->assertIsString($plaintext);
        $this->assertDatabaseHas('node_pairing_tokens', [
            'token_hash' => NodePairingTokenGenerator::hash($plaintext),
            'issued_by_user_id' => $user->id,
        ]);

        // The plaintext token is shown once on the redirect target and is gone
        // on the next render.
        $this->actingAs($user)
            ->withSession(['meridian.issued_pairing_token' => $plaintext])
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Create pairing token')
            ->assertSee($plaintext)
            ->assertSee('Unused pairing tokens');

        $this->actingAs($user)
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertDontSee($plaintext);
    }

    public function test_god_mode_explains_what_an_issued_token_is_for(): void
    {
        $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        $user = $this->godModeUser();

        $response = $this->actingAs($user)
            ->withSession(['meridian.issued_pairing_token' => 'mrdn-pair-example'])
            ->get(route('platform.node.config'));

        $response->assertOk();
        $response->assertSee('What this token is for');
        $response->assertSee('server install', false);
        // The confusion this answers: a pairing token is not a device or Kiosk
        // credential.
        $response->assertSee('Kiosk uses shared workstation', false);
        $response->assertSee('How to use it');
        $response->assertSee('Pair with central');
    }

    public function test_god_mode_revokes_unused_pairing_tokens(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        $user = $this->godModeUser();

        [$firstPlaintext, $first] = $this->issuedToken($central);
        [, $second] = $this->issuedToken($central);
        [, $used] = $this->issuedToken($central);
        $used->forceFill(['used_at' => now()])->save();

        $response = $this->screen('platform.node.config')
            ->actingAs($user)
            ->withoutFollowingRedirects()
            ->method('revokePairingTokens');

        $response->assertRedirect(route('platform.node.config'));

        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertNotNull($second->fresh()->revoked_at);
        // An already-used token is history, not an outstanding credential.
        $this->assertNull($used->fresh()->revoked_at);

        foreach ([$first, $second] as $token) {
            $this->assertDatabaseHas('audit_events', [
                'action' => 'node_pairing_token.revoked',
                'entity_id' => $token->id,
                'actor_user_id' => $user->id,
            ]);
        }

        // A revoked token no longer pairs anything.
        $this->postJson(route('api.node-pairing.store'), [
            'pairing_token' => $firstPlaintext,
            'node_id' => $this->nodeId('juplaya.2027.onsite'),
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'public_key' => 'onsite-public-key',
        ])
            ->assertStatus(401)
            ->assertJsonPath('reason', NodePairingException::REASON_INVALID_TOKEN);
    }

    public function test_the_revoke_action_appears_only_with_unused_tokens_on_a_central_node(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        $user = $this->godModeUser();

        $this->actingAs($user)
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertDontSee('Revoke unused tokens');

        $this->issuedToken($central);

        $this->actingAs($user)
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Revoke unused tokens');
    }

    public function test_revoking_an_already_revoked_token_leaves_it_unchanged(): void
    {
        $central = $this->localNode(Node::ROLE_CENTRAL, 'juplaya.central');
        [, $token] = $this->issuedToken($central);

        $tokens = app(NodePairingTokenService::class);

        $tokens->revoke($token);
        $revokedAt = $token->fresh()->revoked_at;

        $tokens->revoke($token->fresh());

        $this->assertEquals($revokedAt, $token->fresh()->revoked_at);
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'node_pairing_token.revoked')->count(),
        );
    }

    public function test_god_mode_pairs_an_onsite_node_with_central(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');
        $user = $this->godModeUser();

        Http::fake([
            'central.example.org/api/node-pairing' => Http::response($this->centralPairingResponse(), 201),
        ]);

        $response = $this->screen('platform.node.config')
            ->actingAs($user)
            ->withoutFollowingRedirects()
            ->method('pairWithCentral', [
                'pairing' => [
                    'token' => 'mrdn-pair-token',
                    'central_node_url' => 'https://central.example.org',
                ],
            ]);

        $response->assertRedirect(route('platform.node.config'));

        $this->assertSame(
            NodePairingState::STATUS_PAIRED,
            app(NodePairingState::class)->status($onsite->fresh()),
        );

        $this->actingAs($user)
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Paired with central');
    }

    public function test_god_mode_reports_a_refused_pairing_without_changing_state(): void
    {
        $onsite = $this->localNode(Node::ROLE_ONSITE, 'juplaya.2027.onsite');
        $user = $this->godModeUser();

        Http::fake([
            'central.example.org/api/node-pairing' => Http::response([
                'message' => 'The pairing token is not valid.',
            ], 401),
        ]);

        $response = $this->screen('platform.node.config')
            ->actingAs($user)
            ->withoutFollowingRedirects()
            ->method('pairWithCentral', [
                'pairing' => [
                    'token' => 'mrdn-pair-token',
                    'central_node_url' => 'https://central.example.org',
                ],
            ]);

        $response->assertRedirect(route('platform.node.config'));
        $response->assertSessionHasErrors(['pairing.token']);

        $this->assertSame(
            NodePairingState::STATUS_UNPAIRED,
            app(NodePairingState::class)->status($onsite->fresh()),
        );
    }

    public function test_god_mode_does_not_offer_pairing_actions_on_a_development_node(): void
    {
        $this->localNode(Node::ROLE_DEVELOPMENT, 'gerlach.test');
        $user = $this->godModeUser();

        $response = $this->actingAs($user)->get(route('platform.node.config'));

        $response->assertOk();
        $response->assertDontSee('Create pairing token');
        $response->assertDontSee('Pair with central');
        $response->assertSee('Not applicable for this node role');
    }

    private function nodeId(string $nodeName): string
    {
        return $this->nodeIds[$nodeName] ??= (string) Str::uuid();
    }

    private function localNode(string $role, string $name): Node
    {
        return Node::factory()->create([
            'node_name' => $name,
            'node_role' => $role,
            'is_local' => true,
            'public_key' => $name.'-public-key',
        ]);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.node.config' => true,
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: NodePairingToken}
     */
    private function issuedToken(Node $node): array
    {
        $plaintext = NodePairingTokenGenerator::generate();

        $token = NodePairingToken::factory()
            ->forPlaintext($plaintext)
            ->create(['issued_by_node_id' => $node->id]);

        return [$plaintext, $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function centralPairingResponse(): array
    {
        return [
            'central_node' => [
                'id' => (string) Str::uuid(),
                'node_name' => 'juplaya.central',
                'node_role' => Node::ROLE_CENTRAL,
                'public_key' => 'central-public-key',
            ],
            'paired_node' => [
                'id' => (string) Str::uuid(),
                'node_name' => 'juplaya.2027.onsite',
                'node_role' => Node::ROLE_ONSITE,
            ],
            'paired_at' => now()->toIso8601String(),
            'replayed' => false,
        ];
    }
}
