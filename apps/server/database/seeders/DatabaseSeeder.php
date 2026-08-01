<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events are left on, which they were not before the operational
     * scenario existed. `WithoutModelEvents` was harmless while the seed only
     * wrote topology, because an organization and a team carry no derived
     * state. It stops being harmless the moment the seed drives the domain
     * services: a shift takes its department and team name snapshots from a
     * `creating` hook, and a seed with events disabled cannot write one at all.
     * The rest of the scenario has the same shape — accepted Field Reports index
     * their Name References, incidents stamp a timeline — and a seed that
     * bypassed those would be producing rows the product itself never produces,
     * which is the opposite of what a testable fixture is for.
     */
    public function run(): void
    {
        $this->call([
            PermissionCatalogSeeder::class,
            DevelopmentScenarioSeeder::class,
            // After the scenario, so the organizations it creates get their
            // default incident types along with everything created before it.
            IncidentTypeDefaultsSeeder::class,
            /*
             * The operational scenario, in dependency order.
             *
             * Trainings and waivers come first because shift requirements point
             * at them; shifts before assignments and attendance because those
             * are recorded against a shift; equipment before attendance because
             * a check-in hands out a radio; incidents last because they link to
             * Field Reports that must already exist.
             *
             * Every one of these is anchored to the moment the seed runs rather
             * than to a fixed date, so the schedule lines up with the current
             * day and hour and the operational surfaces have live work on them.
             */
            TrainingAndWaiverScenarioSeeder::class,
            ShiftScenarioSeeder::class,
            EquipmentScenarioSeeder::class,
            AttendanceScenarioSeeder::class,
            DocumentScenarioSeeder::class,
            IncidentScenarioSeeder::class,
            IntakeScenarioSeeder::class,
            // Late, because every picture it writes hangs off something one of
            // the seeders above created — a staff row, a department, a Field
            // Report.
            ImageScenarioSeeder::class,
            /*
             * Last of all. An event inside its active window freezes branding,
             * policies, procedures, and fragments organization-wide, so the
             * gates open only once the library and the logos above are laid
             * down. That is the order it happens in reality, and it is the only
             * order that produces a frozen event whose governance content could
             * actually have been authored through the product.
             */
            OpenEventWindowSeeder::class,
        ]);
    }
}
