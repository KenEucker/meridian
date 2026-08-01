<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Shift\ShiftAdminService;
use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;

/**
 * The schedule, anchored to the hour the seed runs.
 *
 * This is the part the old seed had none of, and its absence is why the four
 * department operations surfaces opened onto an empty department: they are all
 * about the shift in front of you, and there was never a shift in front of you.
 *
 * Every window here is expressed as an offset from {@see ScenarioClock}, so the
 * scenario is the same shape whether it is seeded at nine in the morning or
 * eleven at night. The Logistics Desk indexes twelve hours back and thirty-six
 * forward, so the running event's shifts are laid out inside that horizon on
 * purpose — one that ended long enough ago to be finished, one that ended within
 * the hour, one running now, and two still to come. That spread is what makes
 * check-in, check-out, no-show, unscheduled addition, and hours correction all
 * reachable in the same sitting.
 *
 * The upcoming event carries the signup schedule instead. A signup window is
 * only meaningful on work nobody has started, and the four shifts there are
 * shaped to produce four different answers: one that accepts, one that is full,
 * one gated on a lapsed waiver, and one whose window has closed.
 */
class ShiftScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;
        $shifts = app(ShiftAdminService::class);

        $this->seedRunningEventShifts($context, $shifts);
        $this->seedUpcomingEventShifts($context, $shifts);
    }

    private function seedRunningEventShifts(ScenarioContext $context, ShiftAdminService $shifts): void
    {
        $event = $context->event();
        $rangers = $context->department('RANGERS');
        $dirt = $context->team('RANGERS', 'DIRT');
        $command = $context->team('RANGERS', 'COMMAND');
        $dana = $context->user('dana');

        // Finished long enough ago to be settled. Its hours are the ones the
        // attendance seeder freezes, so the desk has a correction to refuse.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Sunrise Patrol',
            'starts_at' => ScenarioClock::hoursAgo(10),
            'ends_at' => ScenarioClock::hoursAgo(4),
            'capacity' => 3,
        ]);

        // Ended within the hour. Its hours are still open, so this is the card
        // an operator corrects (SLB-031).
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Morning Patrol',
            'starts_at' => ScenarioClock::hoursAgo(7),
            'ends_at' => ScenarioClock::hoursAgo(1),
            'capacity' => 3,
        ]);

        /*
         * Running now, and deliberately under-staffed: capacity four against two
         * assignments, so Department Overview raises its coverage gap and the
         * desk has somebody to add. This is the shift almost every operational
         * action in the product is exercised against.
         */
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Day Patrol',
            'starts_at' => ScenarioClock::hoursAgo(2),
            'ends_at' => ScenarioClock::hoursFromNow(4),
            'capacity' => 4,
        ]);

        // Later today, with signup open, so the desk shows a future signup and
        // the staff board has something to withdraw from.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Swing Patrol',
            'starts_at' => ScenarioClock::hoursFromNow(3),
            'ends_at' => ScenarioClock::hoursFromNow(9),
            'capacity' => 3,
            'signup_opens_at' => ScenarioClock::daysAgo(7),
            'signup_closes_at' => ScenarioClock::hoursFromNow(2),
        ]);

        // Inside the desk's thirty-six hour horizon but on the far side of the
        // night, and gated on the training Felix has not completed.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Overnight Patrol',
            'starts_at' => ScenarioClock::hoursFromNow(13),
            'ends_at' => ScenarioClock::hoursFromNow(19),
            'capacity' => 3,
            'signup_opens_at' => ScenarioClock::daysAgo(7),
            'signup_closes_at' => ScenarioClock::hoursFromNow(12),
            'required_training_ids' => $this->trainingIds($rangers, ['Radio Training']),
        ]);

        // Cancelled, so the restore path has a subject and the desk has a shift
        // it must not offer attendance against.
        $standby = $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Storm Standby',
            'starts_at' => ScenarioClock::hoursFromNow(6),
            'ends_at' => ScenarioClock::hoursFromNow(12),
            'capacity' => 2,
        ]);

        if (! $standby->isCancelled()) {
            $shifts->cancel($standby, $dana);
        }

        /*
         * A second team's shift, running at the same time. This is what makes
         * the eligible-team refusal reachable: a Dirt member standing at the
         * desk is on-site and the shift has started, and the addition is still
         * refused because the shift belongs to Command.
         */
        $this->shift($shifts, $rangers, $dana, $event, $command, [
            'title' => 'Command Day Watch',
            'starts_at' => ScenarioClock::hoursAgo(1),
            'ends_at' => ScenarioClock::hoursFromNow(5),
            'capacity' => 2,
        ]);

        // Two more departments, so the department switcher has somewhere to go
        // and every department is not a copy of Rangers.
        $gate = $context->department('GATE');
        $this->shift($shifts, $gate, $context->user('gabe'), $event, $context->team('GATE', 'OPERATOR'), [
            'title' => 'Gate Morning',
            'starts_at' => ScenarioClock::hoursAgo(6),
            'ends_at' => ScenarioClock::now(),
            'capacity' => 2,
        ]);

        $this->shift($shifts, $gate, $context->user('gabe'), $event, $context->team('GATE', 'OPERATOR'), [
            'title' => 'Gate Afternoon',
            'starts_at' => ScenarioClock::now(),
            'ends_at' => ScenarioClock::hoursFromNow(6),
            'capacity' => 2,
        ]);

        $dpw = $context->department('DPW');
        $this->shift($shifts, $dpw, $context->user('dex'), $event, $context->team('DPW', 'LOGISTICS'), [
            'title' => 'Load In',
            'starts_at' => ScenarioClock::hoursFromNow(2),
            'ends_at' => ScenarioClock::hoursFromNow(8),
            'capacity' => 2,
        ]);
    }

    /**
     * The signup schedule, on the event nobody has started working.
     *
     * Four shifts, four different answers. A board where every row accepts
     * proves only that the button is wired up; these are shaped so each
     * documented refusal has a row that produces it.
     */
    private function seedUpcomingEventShifts(ScenarioContext $context, ShiftAdminService $shifts): void
    {
        $event = $context->upcomingEvent();
        $rangers = $context->department('RANGERS');
        $dirt = $context->team('RANGERS', 'DIRT');
        $dana = $context->user('dana');
        $day = ScenarioClock::daysFromNow(42);

        // Open, and takes anybody. The one that accepts.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Decompression Setup',
            'starts_at' => $day->copy()->setTime(10, 0),
            'ends_at' => $day->copy()->setTime(16, 0),
            'capacity' => 4,
            'signup_opens_at' => ScenarioClock::daysAgo(7),
            'signup_closes_at' => ScenarioClock::daysFromNow(40),
        ]);

        // Overlaps Setup, so signing up for both produces the overlap warning
        // technical spec 20.5 requires be a warning rather than a refusal.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Decompression Kitchen',
            'starts_at' => $day->copy()->setTime(12, 0),
            'ends_at' => $day->copy()->setTime(18, 0),
            'capacity' => 4,
            'signup_opens_at' => ScenarioClock::daysAgo(7),
            'signup_closes_at' => ScenarioClock::daysFromNow(40),
        ]);

        // Capacity one, and the assignment seeder fills it. The full one.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Decompression Teardown',
            'starts_at' => ScenarioClock::daysFromNow(45)->setTime(10, 0),
            'ends_at' => ScenarioClock::daysFromNow(45)->setTime(16, 0),
            'capacity' => 1,
            'signup_opens_at' => ScenarioClock::daysAgo(7),
            'signup_closes_at' => ScenarioClock::daysFromNow(43),
        ]);

        // Gated on the field waiver Quinn let lapse. The requirement one.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Decompression Night Watch',
            'starts_at' => ScenarioClock::daysFromNow(43)->setTime(20, 0),
            'ends_at' => ScenarioClock::daysFromNow(44)->setTime(2, 0),
            'capacity' => 3,
            'signup_opens_at' => ScenarioClock::daysAgo(7),
            'signup_closes_at' => ScenarioClock::daysFromNow(41),
            'required_waiver_ids' => $this->waiverIds($context, ['Ranger Field Waiver']),
        ]);

        // Its window shut yesterday. The closed one.
        $this->shift($shifts, $rangers, $dana, $event, $dirt, [
            'title' => 'Decompression Greeters',
            'starts_at' => ScenarioClock::daysFromNow(44)->setTime(9, 0),
            'ends_at' => ScenarioClock::daysFromNow(44)->setTime(15, 0),
            'capacity' => 4,
            'signup_opens_at' => ScenarioClock::daysAgo(30),
            'signup_closes_at' => ScenarioClock::daysAgo(1),
        ]);
    }

    /**
     * Create a shift once, addressed by the natural key a reader would use.
     *
     * Idempotent on event, department, and title rather than on id, so a re-seed
     * against an existing database recognizes the shift it already made instead
     * of stacking a second copy of the whole schedule.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function shift(
        ShiftAdminService $shifts,
        Department $department,
        User $actor,
        Event $event,
        Team $team,
        array $attributes,
    ): Shift {
        $existing = Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->where('title', $attributes['title'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $shifts->create(
            $department,
            [
                'event_id' => (string) $event->id,
                'eligible_team_id' => (string) $team->id,
                ...$attributes,
            ],
            $actor,
        );
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function trainingIds(Department $department, array $names): array
    {
        return Training::query()
            ->where('department_id', $department->id)
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function waiverIds(ScenarioContext $context, array $names): array
    {
        return Waiver::query()
            ->where('organization_id', $context->organization()->id)
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
