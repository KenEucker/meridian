<?php

namespace Tests\Feature;

use App\Domain\Incidents\IncidentTypeDefaults;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Services\Incidents\IncidentTypeProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Default incident types for an organization (INC-007; requirements
 * "Configurable areas").
 */
class IncidentTypeDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_with_no_types_gets_the_defaults(): void
    {
        $organization = Organization::factory()->create();

        $created = app(IncidentTypeProvisioner::class)->ensureDefaults($organization);

        $this->assertSame(count(IncidentTypeDefaults::names()), $created);
        $this->assertEqualsCanonicalizing(
            IncidentTypeDefaults::names(),
            IncidentType::query()
                ->where('organization_id', $organization->id)
                ->pluck('name')
                ->all(),
        );
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $organization = Organization::factory()->create();
        $provisioner = app(IncidentTypeProvisioner::class);

        $provisioner->ensureDefaults($organization);

        $this->assertSame(0, $provisioner->ensureDefaults($organization));
        $this->assertSame(
            count(IncidentTypeDefaults::names()),
            IncidentType::query()->where('organization_id', $organization->id)->count(),
        );
    }

    public function test_it_leaves_an_organizations_own_types_alone(): void
    {
        $organization = Organization::factory()->create();

        // Their own name, and one of the defaults under a different casing.
        IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Sound Complaint',
        ]);
        IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'medical',
        ]);

        app(IncidentTypeProvisioner::class)->ensureDefaults($organization);

        $names = IncidentType::query()
            ->where('organization_id', $organization->id)
            ->pluck('name')
            ->all();

        $this->assertContains('Sound Complaint', $names);
        $this->assertContains('medical', $names);
        $this->assertNotContains('Medical', $names);
    }

    public function test_an_archived_default_is_not_restored(): void
    {
        $organization = Organization::factory()->create();

        // Archiving a type is a decision; re-creating it would quietly undo it.
        IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Weather',
            'archived_at' => Carbon::parse('2027-07-01T00:00:00Z'),
        ]);

        app(IncidentTypeProvisioner::class)->ensureDefaults($organization);

        $this->assertSame(
            1,
            IncidentType::query()
                ->where('organization_id', $organization->id)
                ->where('name', 'Weather')
                ->count(),
        );
        $this->assertNotNull(
            IncidentType::query()
                ->where('organization_id', $organization->id)
                ->where('name', 'Weather')
                ->value('archived_at'),
        );
    }

    public function test_defaults_do_not_cross_organizations(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();

        app(IncidentTypeProvisioner::class)->ensureDefaults($organization);

        $this->assertSame(
            0,
            IncidentType::query()->where('organization_id', $other->id)->count(),
        );
    }

    public function test_the_seeder_covers_organizations_that_already_exist(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();

        $this->seed(\Database\Seeders\IncidentTypeDefaultsSeeder::class);

        foreach ([$organization, $other] as $target) {
            $this->assertSame(
                count(IncidentTypeDefaults::names()),
                IncidentType::query()->where('organization_id', $target->id)->count(),
            );
        }
    }

    public function test_the_default_names_are_distinct(): void
    {
        // The only property this suite asserts about the list's contents. Which
        // categories an organization starts with is a product decision that
        // belongs in `IncidentTypeDefaults`, not one a test should pin; a
        // duplicate would just make the provisioner's first run a no-op for the
        // second copy.
        $names = array_map('mb_strtolower', IncidentTypeDefaults::names());

        $this->assertSame($names, array_values(array_unique($names)));
    }
}
