<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeHealthReport;
use App\Models\User;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\NodeHealth\NodeHealthException;
use App\Services\NodeHealth\NodeHealthReportBuilder;
use App\Services\NodeHealth\NodeHealthReportPayload;
use App\Services\NodeHealth\NodeHealthReportReceiver;
use App\Services\Offline\OfflineReadSetProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sanitized node health reports over the signed node channel (technical spec
 * 22A.11; SYS-037 through SYS-040).
 */
class NodeHealthReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{public_key: string, private_key: string} */
    private array $peerKeys;

    private Node $localNode;

    private Node $peerNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(OfflineReadSetProbe::class, new class extends OfflineReadSetProbe
        {
            public function __construct() {}

            public function isAvailable(): bool
            {
                return true;
            }
        });
    }

    /**
     * This install is central; it knows one paired on-site peer whose keys we
     * hold, so we can build reports the peer would have signed.
     */
    private function asCentralInstall(): void
    {
        $this->peerKeys = app(NodeKeyPairGenerator::class)->generate();

        $this->localNode = Node::factory()->central()->signing()->create([
            'node_name' => 'test.central',
        ]);

        $this->peerNode = Node::factory()->onsite()->remote()->create([
            'node_name' => 'test.onsite',
            'public_key' => $this->peerKeys['public_key'],
        ]);
    }

    private function peerPayload(?CarbonImmutable $generatedAt = null): NodeHealthReportPayload
    {
        return NodeHealthReportPayload::create(
            sourceNodeId: (string) $this->peerNode->getKey(),
            reportUuid: (string) Str::uuid(),
            overallStatus: 'warning',
            nodeName: 'test.onsite',
            nodeRole: Node::ROLE_ONSITE,
            meridianVersion: '0.0.57',
            configSchemaVersion: 1,
            categoryStatuses: ['application' => 'healthy', 'sync' => 'warning'],
            summary: ['queued_operations' => 12, 'disk_free_bytes' => 1000],
            warnings: [['key' => 'sync.node', 'status' => 'warning', 'summary' => 'Operations queued.']],
            generatedAt: $generatedAt ?? CarbonImmutable::now(),
        );
    }

    private function signedByPeer(NodeHealthReportPayload $payload): NodeHealthReportPayload
    {
        return $payload->signedWith(
            app(NodeSignatureAlgorithm::class)->sign($payload->canonicalPayload(), $this->peerKeys['private_key']),
        );
    }

    public function test_a_verified_report_is_stored_and_replaces_the_previous_one(): void
    {
        $this->asCentralInstall();

        $first = $this->signedByPeer($this->peerPayload());
        $second = $this->signedByPeer($this->peerPayload());

        app(NodeHealthReportReceiver::class)->receive($first);
        app(NodeHealthReportReceiver::class)->receive($second);

        $this->assertSame(1, NodeHealthReport::query()->where('node_id', $this->peerNode->getKey())->count());
        $this->assertSame(
            $second->reportUuid,
            NodeHealthReport::query()->where('node_id', $this->peerNode->getKey())->value('report_uuid'),
        );
    }

    public function test_a_tampered_report_is_refused_and_audited(): void
    {
        $this->asCentralInstall();

        $payload = $this->signedByPeer($this->peerPayload());

        $tampered = NodeHealthReportPayload::fromArray(
            array_merge($payload->toArray(), ['overall_status' => 'healthy']),
        );

        try {
            app(NodeHealthReportReceiver::class)->receive($tampered);
            $this->fail('A tampered report must be refused.');
        } catch (NodeHealthException $exception) {
            $this->assertSame('signature_rejected', $exception->reason);
        }

        $this->assertSame(0, NodeHealthReport::query()->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => NodeHealthReportReceiver::AUDIT_REFUSED,
        ]);
    }

    public function test_a_stale_report_is_refused(): void
    {
        $this->asCentralInstall();

        $payload = $this->signedByPeer($this->peerPayload(CarbonImmutable::now()->subHours(2)));

        $this->expectException(NodeHealthException::class);

        app(NodeHealthReportReceiver::class)->receive($payload);
    }

    public function test_a_report_from_an_unknown_node_is_refused(): void
    {
        $this->asCentralInstall();

        $payload = $this->peerPayload();
        $unknown = NodeHealthReportPayload::fromArray(
            array_merge($payload->toArray(), [
                'source_node_id' => (string) Str::uuid(),
                'signature' => 'irrelevant',
            ]),
        );

        try {
            app(NodeHealthReportReceiver::class)->receive($unknown);
            $this->fail('An unknown node must be refused.');
        } catch (NodeHealthException $exception) {
            $this->assertSame('unknown_source_node', $exception->reason);
        }
    }

    public function test_the_http_endpoint_stores_a_signed_report(): void
    {
        $this->asCentralInstall();

        $payload = $this->signedByPeer($this->peerPayload());

        $this->postJson(route('api.node-health-report.store'), $payload->toArray())
            ->assertOk()
            ->assertJson(['status' => 'stored']);

        $this->assertSame(1, NodeHealthReport::query()->count());
    }

    public function test_the_http_endpoint_refuses_a_bad_signature(): void
    {
        $this->asCentralInstall();

        $payload = $this->peerPayload()->signedWith('not-a-signature');

        $this->postJson(route('api.node-health-report.store'), $payload->toArray())
            ->assertForbidden();
    }

    public function test_the_builder_produces_a_sanitized_report(): void
    {
        $node = Node::factory()->onsite()->signing()->create();

        $payload = app(NodeHealthReportBuilder::class)->build($node);
        $serialized = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);

        // Whitelist shape only: statuses, versions, counts, warning lines.
        $this->assertSame((string) $node->getKey(), $payload->sourceNodeId);
        $this->assertNotSame('', $payload->overallStatus);
        $this->assertArrayHasKey('queued_operations', $payload->summary);

        // Nothing configuration-like leaks: neither the database credentials
        // from the test environment nor the application key may appear anywhere
        // in a report (SYS-039).
        $this->assertStringNotContainsString('DB_PASSWORD', $serialized);
        $this->assertStringNotContainsString((string) config('app.key'), $serialized);

        foreach ($payload->warnings as $warning) {
            $this->assertSame(['key', 'status', 'summary'], array_keys($warning));
        }
    }

    public function test_the_command_stores_the_local_report_and_survives_central_being_unreachable(): void
    {
        $node = Node::factory()->onsite()->signing()->create([
            'central_node_url' => 'https://central.invalid',
        ]);

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->artisan('meridian:health-report')->assertExitCode(0);

        $this->assertSame(1, NodeHealthReport::query()->where('node_id', $node->getKey())->count());
    }

    public function test_a_stale_stored_report_is_labelled_stale(): void
    {
        $report = NodeHealthReport::factory()->create([
            'generated_at' => CarbonImmutable::now()->subHours(3),
        ]);

        $this->assertTrue($report->isStale());

        $fresh = NodeHealthReport::factory()->create([
            'node_id' => Node::factory()->create()->getKey(),
            'generated_at' => CarbonImmutable::now(),
        ]);

        $this->assertFalse($fresh->isStale());
    }

    public function test_the_node_health_screen_shows_reports_for_a_permitted_user(): void
    {
        $this->asCentralInstall();

        app(NodeHealthReportReceiver::class)->receive($this->signedByPeer($this->peerPayload()));

        $user = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.system.diagnostics' => true,
        ]]);

        $response = $this->actingAs($user)->get(route('platform.system.node-health'));

        $response->assertOk();
        $response->assertSee('test.onsite');
        $response->assertSee('0.0.57');
    }
}
