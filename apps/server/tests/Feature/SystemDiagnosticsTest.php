<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\User;
use App\Services\Diagnostics\Checks\NodeSyncCheck;
use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\Diagnostics\DiagnosticRunner;
use App\Services\Diagnostics\DiagnosticStatus;
use App\Services\Node\NodeOperationRecorder;
use App\Services\PowerSync\PowerSyncHealthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The diagnostics framework: statuses, overall health aggregation, required
 * versus optional checks, screen access, and CLI exit codes (technical spec
 * 22A.8, 22A.9, 22A.12; SYS-029 through SYS-033, SYS-036, SYS-041).
 */
class SystemDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markPowerSyncAvailable();
    }

    private function fakeCheck(string $key, string $status, bool $required, string $category = DiagnosticCategory::APPLICATION): DiagnosticCheck
    {
        return new class($key, $status, $required, $category) implements DiagnosticCheck
        {
            public function __construct(
                private readonly string $key,
                private readonly string $status,
                private readonly bool $required,
                private readonly string $category,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function category(): string
            {
                return $this->category;
            }

            public function required(): bool
            {
                return $this->required;
            }

            public function run(): DiagnosticResult
            {
                return new DiagnosticResult($this->status, "The {$this->key} check reports {$this->status}.");
            }
        };
    }

    private function runnerWith(DiagnosticCheck ...$checks): DiagnosticRunner
    {
        $runner = new DiagnosticRunner;

        foreach ($checks as $check) {
            $runner->register($check);
        }

        return $runner;
    }

    public function test_overall_health_is_critical_only_for_a_required_critical_check(): void
    {
        $report = $this->runnerWith(
            $this->fakeCheck('a', DiagnosticStatus::HEALTHY, true),
            $this->fakeCheck('b', DiagnosticStatus::CRITICAL, true),
        )->run();

        $this->assertSame(DiagnosticStatus::CRITICAL, $report->overallStatus());
        $this->assertTrue($report->hasRequiredCritical());
    }

    public function test_a_failed_optional_integration_degrades_to_warning_not_critical(): void
    {
        $report = $this->runnerWith(
            $this->fakeCheck('a', DiagnosticStatus::HEALTHY, true),
            $this->fakeCheck('integration', DiagnosticStatus::CRITICAL, false, DiagnosticCategory::INTEGRATIONS),
        )->run();

        $this->assertSame(DiagnosticStatus::WARNING, $report->overallStatus());
        $this->assertFalse($report->hasRequiredCritical());
    }

    public function test_unknown_and_not_applicable_aggregate_correctly(): void
    {
        $this->assertSame(DiagnosticStatus::WARNING, $this->runnerWith(
            $this->fakeCheck('a', DiagnosticStatus::UNKNOWN, true),
        )->run()->overallStatus());

        $this->assertSame(DiagnosticStatus::HEALTHY, $this->runnerWith(
            $this->fakeCheck('a', DiagnosticStatus::UNKNOWN, false),
            $this->fakeCheck('b', DiagnosticStatus::NOT_APPLICABLE, true),
            $this->fakeCheck('c', DiagnosticStatus::HEALTHY, true),
        )->run()->overallStatus());
    }

    public function test_a_throwing_check_does_not_stop_the_run_and_hides_the_message(): void
    {
        $throwing = new class implements DiagnosticCheck
        {
            public function key(): string
            {
                return 'exploding';
            }

            public function label(): string
            {
                return 'Exploding';
            }

            public function category(): string
            {
                return DiagnosticCategory::APPLICATION;
            }

            public function required(): bool
            {
                return true;
            }

            public function run(): DiagnosticResult
            {
                throw new \RuntimeException('pgsql://user:password@host/db exploded');
            }
        };

        $report = $this->runnerWith($throwing, $this->fakeCheck('after', DiagnosticStatus::HEALTHY, true))->run();

        $this->assertCount(2, $report->checks);
        $this->assertSame(DiagnosticStatus::CRITICAL, $report->checks[0]->result->status);
        // Exception messages can carry connection strings; only the class
        // travels into results.
        $this->assertStringNotContainsString('password', json_encode($report->toArray()));
        $this->assertSame(\RuntimeException::class, $report->checks[0]->result->details['exception']);
    }

    public function test_the_real_registered_suite_runs_and_covers_the_required_categories(): void
    {
        Node::factory()->signing()->create();

        $report = app(DiagnosticRunner::class)->run();
        $categories = array_keys($report->byCategory());

        foreach ([
            DiagnosticCategory::APPLICATION,
            DiagnosticCategory::SECURITY,
            DiagnosticCategory::CONFIGURATION,
            DiagnosticCategory::DATABASE,
            DiagnosticCategory::CACHE,
            DiagnosticCategory::QUEUE,
            DiagnosticCategory::STORAGE,
            DiagnosticCategory::WIRING,
            DiagnosticCategory::SYNC,
            DiagnosticCategory::NODE,
            DiagnosticCategory::INTEGRATIONS,
        ] as $category) {
            $this->assertContains($category, $categories, "missing category {$category}");
        }

        // On a healthy test install nothing required is critical.
        $this->assertFalse($report->hasRequiredCritical(), json_encode($report->toArray()));
    }

    public function test_an_intentionally_offline_onsite_node_is_not_failed(): void
    {
        $node = Node::factory()->onsite()->signing()->create();

        // Queue an operation so the node looks like an offline on-site node
        // holding a backlog for central.
        $user = User::factory()->create();
        app(NodeOperationRecorder::class)->record(
            operationType: 'test.noop',
            entityType: 'test',
            entityId: (string) Str::uuid(),
            actorUser: $user,
            originNode: $node,
        );

        $check = app(NodeSyncCheck::class);
        $result = $check->run();

        $this->assertSame(DiagnosticStatus::HEALTHY, $result->status);
        $this->assertTrue((bool) $result->details['expected_offline']);
        $this->assertStringContainsString('Expected while this on-site node has no internet', $result->summary);
    }

    public function test_the_diagnostics_screen_renders_for_a_permitted_user(): void
    {
        Node::factory()->signing()->create();

        $response = $this->actingAs($this->diagnosticsUser())->get(route('platform.system.diagnostics'));

        $response->assertOk();
        $response->assertSee('Overall:');
        $response->assertSee('Scheduler heartbeat');
    }

    public function test_the_export_endpoint_enforces_its_own_permission(): void
    {
        $viewer = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.system.diagnostics' => true,
        ]]);

        $this->actingAs($viewer)
            ->post(route('platform.system.diagnostics').'/export')
            ->assertForbidden();
    }

    public function test_the_cli_exits_nonzero_on_a_required_critical_check(): void
    {
        $this->app->instance(DiagnosticRunner::class, $this->runnerWith(
            $this->fakeCheck('down', DiagnosticStatus::CRITICAL, true),
        ));

        $this->artisan('meridian:diagnostics')->assertExitCode(1);
    }

    public function test_the_cli_exits_zero_when_healthy_and_emits_json(): void
    {
        $this->app->instance(DiagnosticRunner::class, $this->runnerWith(
            $this->fakeCheck('up', DiagnosticStatus::HEALTHY, true),
        ));

        $this->artisan('meridian:diagnostics --json')->assertExitCode(0);
    }

    private function diagnosticsUser(): User
    {
        return User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.system.diagnostics' => true,
            'platform.system.diagnostics.export' => true,
        ]]);
    }

    private function markPowerSyncAvailable(bool $available = true): void
    {
        $this->instance(PowerSyncHealthClient::class, new class($available) extends PowerSyncHealthClient
        {
            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        });
    }
}
