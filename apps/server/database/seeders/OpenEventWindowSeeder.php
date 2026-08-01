<?php

namespace Database\Seeders;

use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;

/**
 * Opens the gates, and it goes last on purpose.
 *
 * An event inside its active window freezes organization governance product-wide
 * — branding, policies, procedures, and fragments all refuse edits on every node
 * until the window closes. That freeze is real behaviour worth being able to
 * meet, so the seeded event ends up inside its window. But it also means the
 * document library and the branding assets have to be laid down first, because
 * an organization authors those before it opens rather than during.
 *
 * So the scenario is built in the order it would really have happened, and this
 * is the last step: the gates open, and from here on a mid-event policy edit is
 * refused exactly the way the product intends.
 *
 * To administer governance against this database, close the window and the
 * whole organization thaws:
 *
 *     php artisan tinker --execute='App\Models\Event::query()->update(["active_event_window_starts_at" => null, "active_event_window_ends_at" => null]);'
 */
class OpenEventWindowSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;
        $event = $context->event();

        if ($event->active_event_window_starts_at !== null) {
            return;
        }

        // A day either side of the scheduled run, which is how an on-site window
        // is usually set: authority moves to the field before the first shift
        // and stays there until the last one is closed out.
        $event->forceFill([
            'active_event_window_starts_at' => $event->starts_at?->copy()->subDay() ?? ScenarioClock::daysAgo(3),
            'active_event_window_ends_at' => $event->ends_at?->copy()->addDay() ?? ScenarioClock::daysFromNow(4),
        ])->save();
    }
}
