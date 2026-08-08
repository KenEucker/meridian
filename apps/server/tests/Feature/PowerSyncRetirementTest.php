<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Diagnostics\Checks\OfflineReadSetCheck;
use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticRunner;
use App\Services\Diagnostics\DiagnosticStatus;
use App\Services\Offline\OfflineReadSetProbe;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * PowerSync is retired (ADR-0003; M18.51).
 *
 * This is the test that keeps it retired. Deleting `config/powersync.php`,
 * `PowerSyncHealthClient`, `PowerSyncCheck`, and `deploy/powersync` is a change
 * anybody can undo by accident — a `config('powersync.endpoint')` reintroduced
 * in a merge would read `null` and fail silently rather than loudly, which is
 * the failure mode worth a test rather than a code review.
 *
 * What is forbidden is a reference that would *resolve* — a configuration key,
 * an environment variable, a class, a deleted deploy path — rather than the
 * word itself. The word survives on purpose in the ADR that retired it, in the
 * milestone plan, in the traceability matrix, in the changelog, and in the
 * comments explaining why the offline read set is shaped the way it is: those
 * are the record of the decision, and a rule that erased them would be asking
 * the project to forget why it made one.
 */
class PowerSyncRetirementTest extends TestCase
{
    public function test_no_powersync_configuration_is_registered(): void
    {
        $this->assertNull(config('powersync'));
        $this->assertNull(config('meridian.event_mode.require_powersync'));

        $this->assertFileDoesNotExist(config_path('powersync.php'));
        $this->assertDirectoryDoesNotExist(app_path('Services/PowerSync'));
        $this->assertFileDoesNotExist(app_path('Services/Diagnostics/Checks/PowerSyncCheck.php'));
    }

    public function test_no_application_code_references_powersync_configuration(): void
    {
        /*
         * Every form a live reference could take: the config namespace, the
         * environment variables that fed it, the event-mode flag it gated, the
         * classes that read it, and the deploy directory it was served from.
         */
        $forbidden = [
            "config('powersync",
            'config("powersync',
            'POWERSYNC_',
            'require_powersync',
            'Services\\PowerSync',
            'Services/PowerSync',
            'PowerSyncHealthClient',
            'PowerSyncCheck',
            'deploy/powersync',
        ];

        $offenders = [];

        foreach ($this->applicationFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $token) {
                if (str_contains($contents, $token)) {
                    $offenders[] = $this->relativePath($file->getPathname()).' ('.$token.')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'PowerSync was retired by ADR-0003, so nothing may still resolve against it: '
                .implode(', ', $offenders),
        );
    }

    public function test_the_env_example_offers_no_powersync_keys(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString('POWERSYNC', $env);
        $this->assertStringContainsString('MERIDIAN_EVENT_MODE_REQUIRE_OFFLINE_READ_SET=true', $env);
    }

    public function test_the_deploy_service_is_gone_and_logical_replication_is_kept(): void
    {
        /*
         * The tracked files rather than the directory: a working copy that ran
         * the service still holds an untracked `deploy/powersync/.env`, and a
         * test that failed on somebody's local secrets file would be reporting
         * on their machine rather than on the repository.
         */
        foreach (['compose.yaml', 'service.yaml', 'sync-config.yaml', 'README.md', '.env.example'] as $file) {
            $this->assertFileDoesNotExist(base_path('../../deploy/powersync/'.$file));
        }

        $this->assertFileDoesNotExist(
            base_path('../../deploy/docker/postgres/initdb/01-powersync-prerequisites.sh'),
        );

        $compose = (string) file_get_contents(base_path('../../deploy/docker/compose.yaml'));

        // Node-to-node sync is a separate mechanism and keeps what it needs.
        $this->assertStringContainsString('wal_level=logical', $compose);
        $this->assertStringNotContainsString('POWERSYNC', $compose);
        $this->assertStringNotContainsString('initdb', $compose);
    }

    public function test_the_sync_category_carries_the_offline_read_set_check(): void
    {
        $keys = array_map(
            static fn (DiagnosticCheck $check): string => $check->key(),
            app(DiagnosticRunner::class)->checks(),
        );

        $this->assertContains('sync.offline_read_set', $keys);
        $this->assertNotContains('sync.powersync', $keys);
    }

    public function test_the_replacement_check_reports_a_servable_read_set(): void
    {
        // Pinned off so the check answers from the probe rather than from
        // whatever node role this test database happens to hold.
        config(['meridian.event_mode.enabled' => false]);

        $check = app(OfflineReadSetCheck::class);

        $this->assertSame('sync.offline_read_set', $check->key());
        $this->assertSame(DiagnosticCategory::SYNC, $check->category());

        $result = $check->run();

        $this->assertSame(DiagnosticStatus::HEALTHY, $result->status);
        $this->assertSame(OfflineReadSetProbe::ROUTE, $result->details['route']);
    }

    /**
     * Every PHP source, configuration, and route file the server ships, plus
     * the deploy surface. Vendored dependencies, generated caches, and the
     * changelog are excluded: none of them is code this project maintains.
     *
     * @return list<SplFileInfo>
     */
    private function applicationFiles(): array
    {
        $roots = [
            base_path('app'),
            base_path('config'),
            base_path('routes'),
            base_path('database'),
            base_path('../../deploy'),
            base_path('../client/src'),
            base_path('../kiosk/src'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                // Local, untracked, and never shipped: a developer's own `.env`
                // is not application code and is theirs to clean up.
                if (str_starts_with($file->getFilename(), '.env') && $file->getFilename() !== '.env.example') {
                    continue;
                }

                $files[] = $file;
            }
        }

        return $files;
    }

    private function relativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', base_path('../..'));

        return str_starts_with($normalized, $root)
            ? ltrim(substr($normalized, strlen($root)), '/')
            : $normalized;
    }
}
