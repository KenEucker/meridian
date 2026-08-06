<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditActionCatalog;
use App\Domain\Audit\AuditVerbosity;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditArchivalService;
use App\Services\Audit\AuditPartitioning;
use App\Services\Audit\AuditPolicy;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * How much an organization records, and how much it keeps (requirements 2.4;
 * development process 7.5; data/API 8, 14.1).
 *
 * The two properties worth defending are that no configuration can suppress a
 * required entry, and that no limit destroys history without archiving it
 * first. Everything else here is the ordinary behaviour of the controls.
 */
class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    // ---- Verbosity -------------------------------------------------------

    public function test_an_organization_that_never_chose_records_at_the_documented_default(): void
    {
        $organization = Organization::factory()->create();

        $this->assertSame(AuditVerbosity::Standard, $organization->auditVerbosity());
        $this->assertSame(AuditVerbosity::Standard, AuditVerbosity::default());
    }

    public function test_a_level_omits_the_actions_catalogued_above_it(): void
    {
        $organization = $this->organizationAt(AuditVerbosity::Standard);

        // Detailed, so Standard does not write it.
        $this->record($organization, 'attendance.checked_in');
        // Standard, so it does.
        $this->record($organization, 'department.created');

        $this->assertSame(
            ['department.created'],
            $this->actionsFor($organization),
        );
    }

    public function test_a_higher_level_includes_everything_below_it(): void
    {
        $organization = $this->organizationAt(AuditVerbosity::Detailed);

        $this->record($organization, 'attendance.checked_in');
        $this->record($organization, 'department.created');
        $this->record($organization, 'event_application.approved');

        $this->assertEqualsCanonicalizing(
            ['attendance.checked_in', 'department.created', 'event_application.approved'],
            $this->actionsFor($organization),
        );
    }

    /**
     * The property the whole design rests on: requirements 2.4 and data/API
     * section 8 decide these, not an operator.
     */
    public function test_no_level_or_override_can_suppress_a_required_action(): void
    {
        $organization = $this->organizationAt(AuditVerbosity::Minimal);

        // Every required action switched off as explicitly as the column allows.
        $organization->forceFill([
            'audit_action_overrides' => array_fill_keys(AuditActionCatalog::REQUIRED, false),
        ])->save();

        app(AuditPolicy::class)->forget();

        foreach (AuditActionCatalog::REQUIRED as $action) {
            $this->record($organization, $action);
        }

        $this->assertSame(
            count(AuditActionCatalog::REQUIRED),
            AuditEvent::query()->where('organization_id', $organization->id)->count(),
        );
    }

    public function test_an_override_records_an_action_the_level_would_omit(): void
    {
        $organization = $this->organizationAt(AuditVerbosity::Minimal);
        $organization->forceFill([
            'audit_action_overrides' => ['attendance.checked_in' => true],
        ])->save();

        app(AuditPolicy::class)->forget();

        $this->record($organization, 'attendance.checked_in');
        $this->record($organization, 'department.created');

        $this->assertSame(['attendance.checked_in'], $this->actionsFor($organization));
    }

    public function test_an_override_omits_an_action_the_level_would_record(): void
    {
        $organization = $this->organizationAt(AuditVerbosity::Complete);
        $organization->forceFill([
            'audit_action_overrides' => ['department.created' => false],
        ])->save();

        app(AuditPolicy::class)->forget();

        $this->record($organization, 'department.created');

        $this->assertSame([], $this->actionsFor($organization));
    }

    /**
     * Actions are composed at runtime in several places, so the catalogue
     * cannot be complete. A gap in it must not become missing history.
     */
    public function test_an_uncatalogued_action_is_recorded_at_every_level(): void
    {
        $organization = $this->organizationAt(AuditVerbosity::Minimal);

        $this->record($organization, 'something.nobody.catalogued');

        $this->assertSame(['something.nobody.catalogued'], $this->actionsFor($organization));
    }

    /** Node and system history belongs to the node, not to a preference. */
    public function test_an_entry_with_no_organization_is_always_recorded(): void
    {
        $this->organizationAt(AuditVerbosity::Minimal);

        app(AuditService::class)->record(
            action: 'attendance.checked_in',
            entityType: 'App\\Models\\Node',
            entityId: (string) Str::uuid(),
        );

        $this->assertSame(1, AuditEvent::query()->whereNull('organization_id')->count());
    }

    // ---- Limits and archival --------------------------------------------

    public function test_an_organization_with_no_limits_archives_nothing(): void
    {
        $organization = Organization::factory()->create();
        $this->fill($organization, 5);

        $result = app(AuditArchivalService::class)->enforce($organization);

        $this->assertSame(0, $result['archived']);
        $this->assertSame(5, AuditEvent::query()->where('organization_id', $organization->id)->count());
    }

    public function test_a_row_limit_archives_the_oldest_and_keeps_the_newest(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['audit_max_rows' => 3]);
        $this->fill($organization, 6);

        $result = app(AuditArchivalService::class)->enforce($organization);

        $this->assertSame(3, $result['archived']);
        $this->assertContains('max_rows', $result['reasons']);

        $remaining = AuditEvent::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'like', 'filler.%')
            ->orderBy('created_at')
            ->pluck('action')
            ->all();

        // The three newest survive; the three oldest are the ones archived.
        $this->assertSame(['filler.3', 'filler.4', 'filler.5'], $remaining);
    }

    public function test_a_retention_window_archives_entries_older_than_it(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['audit_retention_days' => 30]);

        $old = $this->record($organization, 'department.created');
        $old->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();
        $this->record($organization, 'department.updated');

        $result = app(AuditArchivalService::class)->enforce($organization);

        $this->assertSame(1, $result['archived']);
        $this->assertContains('retention_days', $result['reasons']);
        $this->assertDatabaseMissing('audit_events', ['id' => $old->id]);
    }

    /**
     * The rule that makes a limit acceptable at all: history leaves the table,
     * not the record.
     */
    public function test_archiving_writes_every_column_to_a_file_before_removing_anything(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['audit_max_rows' => 0]);

        app(AuditService::class)->record(
            action: 'department.created',
            entityType: 'App\\Models\\Department',
            entityId: (string) Str::uuid(),
            organizationId: (string) $organization->id,
            before: ['name' => 'Rangers'],
            after: ['name' => 'Rangers Renamed'],
            reason: 'Corrected at the desk.',
        );

        $result = app(AuditArchivalService::class)->enforce($organization);

        $this->assertSame(1, $result['archived']);

        $contents = Storage::disk('local')->get((string) $result['file']);
        $archived = json_decode(trim($contents), true);

        $this->assertSame('department.created', $archived['action']);
        $this->assertSame(['name' => 'Rangers'], $archived['before_json']);
        $this->assertSame(['name' => 'Rangers Renamed'], $archived['after_json']);
        $this->assertSame('Corrected at the desk.', $archived['reason']);
    }

    public function test_the_archival_is_itself_recorded_and_survives_the_pass(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['audit_max_rows' => 1]);
        $this->fill($organization, 4);

        app(AuditArchivalService::class)->enforce($organization, User::factory()->create());

        $archival = AuditEvent::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'audit.archived')
            ->first();

        $this->assertNotNull($archival);
        $this->assertSame(3, $archival->after_json['rows']);
        $this->assertNotNull($archival->after_json['archive_file']);
    }

    /** The escape is scoped; nothing else in the application may remove a row. */
    public function test_removal_stays_forbidden_outside_the_archival_path(): void
    {
        $organization = Organization::factory()->create();
        $entry = $this->record($organization, 'department.created');

        $this->expectException(RuntimeException::class);

        $entry->delete();
    }

    public function test_the_archival_escape_is_released_even_when_it_throws(): void
    {
        $organization = Organization::factory()->create();
        $entry = $this->record($organization, 'department.created');

        try {
            AuditEvent::withArchival(function (): void {
                throw new RuntimeException('something went wrong mid-archive');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        // If the flag had leaked, this would succeed.
        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    // ---- Usage and partitioning -----------------------------------------

    public function test_usage_reports_what_an_organization_is_holding(): void
    {
        $organization = Organization::factory()->create();
        $this->fill($organization, 3);

        $usage = app(AuditUsage::class)->forOrganization($organization);

        $this->assertSame(3, $usage['rows']);
        $this->assertGreaterThan(0, $usage['bytes']);
        $this->assertNotNull($usage['oldest_at']);
    }

    /**
     * Partitioning answers for the driver it is actually running on.
     *
     * The suite runs on SQLite, where declarative partitioning does not exist
     * and every method answers "not applicable" rather than failing. Run the
     * same file against PostgreSQL — which is what verifies the migration —
     * and the answers flip. Asserting both from one test is what makes it
     * meaningful either way, rather than a test that quietly checks nothing on
     * the database the product actually ships on.
     */
    public function test_partitioning_answers_for_the_driver_it_is_running_on(): void
    {
        $partitioning = app(AuditPartitioning::class);

        $this->assertTrue($partitioning->tableExists());

        if (! $partitioning->isSupported()) {
            $this->assertFalse($partitioning->isPartitioned());
            $this->assertFalse($partitioning->isEnabled());
            $this->assertSame([], $partitioning->partitions());
            $this->assertSame([], $partitioning->ensurePartitions());

            return;
        }

        $this->assertTrue($partitioning->isPartitioned());
        $this->assertNotSame([], $partitioning->partitions());

        // The catch-all is what keeps a row with an unexpected timestamp from
        // being a write that fails.
        $this->assertContains(
            'audit_events_default',
            array_column($partitioning->partitions(), 'name'),
        );
    }

    public function test_the_partition_command_succeeds_on_either_driver(): void
    {
        $partitioning = app(AuditPartitioning::class);

        $this->artisan('meridian:ensure-audit-partitions')
            ->expectsOutputToContain($partitioning->isPartitioned() ? 'partitions present' : 'not partitioned')
            ->assertSuccessful();
    }

    public function test_the_limit_command_skips_organizations_with_no_limits(): void
    {
        Organization::factory()->create();

        $this->artisan('meridian:enforce-audit-limits')
            ->expectsOutputToContain('No organization has audit limits configured.')
            ->assertSuccessful();
    }

    public function test_the_limit_command_archives_for_a_configured_organization(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['audit_max_rows' => 2]);
        $this->fill($organization, 5);

        $this->artisan('meridian:enforce-audit-limits')->assertSuccessful();

        $this->assertSame(
            2,
            AuditEvent::query()
                ->where('organization_id', $organization->id)
                ->where('action', 'like', 'filler.%')
                ->count(),
        );
    }

    // ---- Helpers ---------------------------------------------------------

    private function organizationAt(AuditVerbosity $level): Organization
    {
        $organization = Organization::factory()->create(['audit_verbosity' => $level->value]);

        app(AuditPolicy::class)->forget();

        return $organization;
    }

    private function record(Organization $organization, string $action): ?AuditEvent
    {
        return app(AuditService::class)->record(
            action: $action,
            entityType: 'App\\Models\\Department',
            entityId: (string) Str::uuid(),
            organizationId: (string) $organization->id,
        );
    }

    private function fill(Organization $organization, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $entry = $this->record($organization, 'filler.'.$index);
            $entry?->forceFill(['created_at' => now()->addSeconds($index)])->saveQuietly();
        }
    }

    /**
     * @return list<string>
     */
    private function actionsFor(Organization $organization): array
    {
        return AuditEvent::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->pluck('action')
            ->all();
    }
}
