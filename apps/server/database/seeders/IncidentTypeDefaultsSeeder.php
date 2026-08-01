<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Services\Incidents\IncidentTypeProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Give every organization the default incident types it is missing (INC-007).
 *
 * Organizations created before this existed have none, and an organization with
 * no configured types cannot categorize an incident at all. The provisioner is
 * idempotent and additive, so this is safe to run against a populated database
 * and safe to run twice: it restores nothing an organization archived and
 * removes nothing an organization added.
 */
class IncidentTypeDefaultsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $provisioner = app(IncidentTypeProvisioner::class);

        Organization::query()
            ->orderBy('id')
            ->each(function (Organization $organization) use ($provisioner): void {
                $created = $provisioner->ensureDefaults($organization);

                if ($created > 0) {
                    $this->command?->info(sprintf(
                        'Added %d default incident type(s) to %s.',
                        $created,
                        $organization->name ?? $organization->getKey(),
                    ));
                }
            });
    }
}
