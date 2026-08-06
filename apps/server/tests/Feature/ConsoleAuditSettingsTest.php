<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditVerbosity;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The God Mode audit settings screens (data/API 14.1; requirements 2.4).
 *
 * God Mode only for now, which is the first thing these assert: the controls
 * decide how much of an organization's history exists, and that is a different
 * kind of decision from the ones on the organizer configuration surface.
 */
class ConsoleAuditSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_shows_each_organization_with_its_setting_and_measured_usage(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
            'audit_max_rows' => 1000,
        ]);

        app(AuditService::class)->record(
            action: 'department.created',
            entityType: 'App\\Models\\Department',
            entityId: (string) Str::uuid(),
            organizationId: (string) $organization->id,
        );

        $response = $this->actingAs($this->settingsUser())->get(route('platform.audit.settings'));

        $response->assertOk();
        $response->assertSee('Northwood Collective');
        $response->assertSee('Complete');
        $response->assertSee('1,000 rows');
    }

    public function test_both_screens_require_the_settings_permission(): void
    {
        $organization = Organization::factory()->create();

        // Holding the trail permission is not holding this one: reading the
        // record and deciding what the record will contain are different powers.
        $reader = User::factory()->create([
            'permissions' => ['platform.index' => true, 'platform.audit' => true],
        ]);

        $this->actingAs($reader)->get(route('platform.audit.settings'))->assertForbidden();
        $this->actingAs($reader)
            ->get(route('platform.audit.settings.edit', $organization->id))
            ->assertForbidden();
    }

    public function test_the_edit_screen_names_the_required_actions_as_unchangeable(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->actingAs($this->settingsUser())
            ->get(route('platform.audit.settings.edit', $organization->id));

        $response->assertOk();
        $response->assertSee('event_credential.revoked');
        $response->assertSee('Always recorded');
    }

    public function test_saving_stores_the_level_the_limits_and_the_exceptions(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->settingsUser())
            ->post(route('platform.audit.settings.edit', [$organization->id, 'method' => 'save']), [
                'audit' => [
                    'verbosity' => AuditVerbosity::Detailed->value,
                    'max_rows' => 5000,
                    'max_bytes' => 1048576,
                    'retention_days' => 365,
                    'always' => ['applicant_portal.link_issued'],
                    'never' => ['department.updated'],
                ],
            ])
            ->assertRedirect();

        $organization->refresh();

        $this->assertSame(AuditVerbosity::Detailed, $organization->auditVerbosity());
        $this->assertSame(5000, $organization->audit_max_rows);
        $this->assertSame(1048576, $organization->audit_max_bytes);
        $this->assertSame(365, $organization->audit_retention_days);
        $this->assertSame([
            'department.updated' => false,
            'applicant_portal.link_issued' => true,
        ], $organization->audit_action_overrides);
    }

    /** Changing what will be recorded is itself recorded (ORG-020). */
    public function test_saving_is_audited_with_the_values_before_and_after(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->settingsUser())
            ->post(route('platform.audit.settings.edit', [$organization->id, 'method' => 'save']), [
                'audit' => ['verbosity' => AuditVerbosity::Minimal->value],
            ])
            ->assertRedirect();

        $entry = AuditEvent::query()
            ->where('organization_id', $organization->id)
            ->where('action', 'organization.configuration_updated')
            ->firstOrFail();

        $this->assertNull($entry->before_json['audit_verbosity']);
        $this->assertSame('minimal', $entry->after_json['audit_verbosity']);
    }

    /**
     * An operator who has said both things about one action has contradicted
     * themselves, and the resolution that keeps the record wins.
     */
    public function test_an_action_named_in_both_lists_is_recorded(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->settingsUser())
            ->post(route('platform.audit.settings.edit', [$organization->id, 'method' => 'save']), [
                'audit' => [
                    'verbosity' => AuditVerbosity::Standard->value,
                    'always' => ['department.updated'],
                    'never' => ['department.updated'],
                ],
            ])
            ->assertRedirect();

        $this->assertTrue($organization->refresh()->audit_action_overrides['department.updated']);
    }

    public function test_applying_the_limits_from_the_screen_archives_and_reports(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create(['audit_max_rows' => 1]);

        foreach (range(0, 3) as $index) {
            app(AuditService::class)->record(
                action: 'filler.'.$index,
                entityType: 'App\\Models\\Department',
                entityId: (string) Str::uuid(),
                organizationId: (string) $organization->id,
            );
        }

        $this->actingAs($this->settingsUser())
            ->post(route('platform.audit.settings.edit', [$organization->id, 'method' => 'applyLimits']))
            ->assertRedirect();

        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('organization_id', $organization->id)
                ->where('action', 'like', 'filler.%')
                ->count(),
        );
    }

    private function settingsUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.audit.settings' => true,
            ],
        ]);
    }
}
