<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Training\TrainingAdminService;
use App\Services\Training\TrainingService;
use App\Services\Waiver\WaiverService;
use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;

/**
 * Trainings and waivers, and the completions that make them mean something.
 *
 * These come before shifts because a shift's requirements point at them, and
 * they are the whole reason a signup can be refused for something other than
 * capacity. A scenario where everybody has completed everything proves only the
 * happy path, so the crew is deliberately uneven: Vera and Nora are cleared for
 * everything, Felix is missing the radio training the overnight shift requires,
 * and Quinn has a waiver that has already lapsed. Each of those is a different
 * sentence on the shift board.
 *
 * Everything runs through the domain services rather than through model writes,
 * so the audit trail, the expiry arithmetic, and the prerequisite graph are the
 * ones the product produces rather than a hand-built imitation of them.
 */
class TrainingAndWaiverScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;
        $rangers = $context->department('RANGERS');
        $dana = $context->user('dana');

        $trainings = app(TrainingAdminService::class);
        $completions = app(TrainingService::class);
        $waivers = app(WaiverService::class);

        // Foundational, always open, no schedule to sign up for.
        $basic = $this->training($trainings, $rangers, $dana, [
            'name' => 'Basic Ranger Training',
            'description' => 'Orientation, radio discipline, and the department code of conduct.',
            'expires_after_days' => 730,
        ]);

        /*
         * Scheduled, so it takes signups. Placed two days out rather than at a
         * fixed date so the signup path is reachable on any day the seed runs,
         * and so the training list has a session that has not happened yet.
         */
        $radio = $this->training($trainings, $rangers, $dana, [
            'name' => 'Radio Training',
            'description' => 'Channel discipline, call signs, and the incident net.',
            'expires_after_days' => 365,
            'scheduled_start_at' => ScenarioClock::daysFromNow(2)->setTime(10, 0)->toIso8601String(),
            'scheduled_end_at' => ScenarioClock::daysFromNow(2)->setTime(13, 0)->toIso8601String(),
            'location' => 'Ranger HQ, back room',
            'capacity' => 8,
        ]);

        // A session that has already run, so the completion list has history and
        // the training index has something in the past to render.
        $sandstorm = $this->training($trainings, $rangers, $dana, [
            'name' => 'Sandstorm Response',
            'description' => 'Shelter-in-place, accountability sweeps, and the all-clear.',
            'expires_after_days' => 365,
            'scheduled_start_at' => ScenarioClock::daysAgo(9)->setTime(14, 0)->toIso8601String(),
            'scheduled_end_at' => ScenarioClock::daysAgo(9)->setTime(16, 0)->toIso8601String(),
            'location' => 'Ranger HQ, back room',
            'capacity' => 12,
        ]);

        // Archived, so the restore path has something to restore and the list
        // filter has something to filter.
        $retired = $this->training($trainings, $rangers, $dana, [
            'name' => 'Legacy Radio Procedure',
            'description' => 'Superseded by Radio Training. Kept for the record.',
        ]);

        if (! $retired->isArchived()) {
            $trainings->archive($retired, $dana);
        }

        // Radio Training builds on Basic, which is what puts a locked
        // prerequisite in front of anybody who has not done the first one.
        if (! $radio->prerequisites()->where('prerequisite_training_id', $basic->id)->exists()) {
            $trainings->addPrerequisite($radio, $basic, $dana);
        }

        $this->recordCompletions($completions, $basic, $context, ['vera', 'nora', 'felix', 'quinn', 'sam', 'dana']);
        $this->recordCompletions($completions, $radio, $context, ['vera', 'nora', 'sam', 'dana']);
        $this->recordCompletions($completions, $sandstorm, $context, ['vera', 'sam']);

        // Felix is signed up for the radio session he has not completed, so the
        // training page shows a pending signup rather than only a gap.
        $this->signUp($trainings, $radio, $context, ['felix', 'quinn'], $dana);

        $eventWaiver = $this->waiver(
            $waivers,
            $context,
            'organization',
            (string) $context->organization()->id,
            'Event Participation Waiver',
            'Signed once a year by everybody who works an event.',
            365,
        );

        $rangerWaiver = $this->waiver(
            $waivers,
            $context,
            'department',
            (string) $rangers->id,
            'Ranger Field Waiver',
            'Required for any Ranger shift that leaves the perimeter.',
            365,
        );

        foreach (['vera', 'nora', 'felix', 'quinn', 'sam', 'dana', 'ingrid', 'omar', 'mira'] as $key) {
            $waivers->recordCompletion(
                $eventWaiver,
                $context->staff($key),
                ScenarioClock::daysAgo(20),
                $dana,
            );
        }

        foreach (['vera', 'nora', 'felix', 'sam', 'dana'] as $key) {
            $waivers->recordCompletion(
                $rangerWaiver,
                $context->staff($key),
                ScenarioClock::daysAgo(20),
                $dana,
            );
        }

        /*
         * Quinn's field waiver lapsed a week ago. Recorded as a real completion
         * dated beyond its own expiry window rather than as a missing row,
         * because "never signed it" and "signed it and it ran out" are different
         * facts and only the second one produces the expiry wording.
         */
        $waivers->recordCompletion(
            $rangerWaiver,
            $context->staff('quinn'),
            ScenarioClock::daysAgo(372),
            $dana,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function training(
        TrainingAdminService $trainings,
        Department $department,
        User $actor,
        array $attributes,
    ): Training {
        $existing = Training::query()
            ->where('department_id', $department->id)
            ->where('name', $attributes['name'])
            ->first();

        return $existing ?? $trainings->create($department, $attributes, $actor);
    }

    /**
     * @param  list<string>  $personaKeys
     */
    private function recordCompletions(
        TrainingService $completions,
        Training $training,
        ScenarioContext $context,
        array $personaKeys,
    ): void {
        foreach ($personaKeys as $key) {
            $completions->recordCompletion(
                $training,
                $context->staff($key),
                ScenarioClock::daysAgo(14),
                $context->user('dana'),
            );
        }
    }

    /**
     * @param  list<string>  $personaKeys
     */
    private function signUp(
        TrainingAdminService $trainings,
        Training $training,
        ScenarioContext $context,
        array $personaKeys,
        User $actor,
    ): void {
        foreach ($personaKeys as $key) {
            $staff = $context->staff($key);

            if ($training->signups()->where('staff_id', $staff->id)->whereNull('cancelled_at')->exists()) {
                continue;
            }

            $trainings->signUp($training, $staff, $actor);
        }
    }

    private function waiver(
        WaiverService $waivers,
        ScenarioContext $context,
        string $scopeType,
        string $scopeId,
        string $name,
        string $description,
        int $expiresAfterDays,
    ): Waiver {
        $existing = Waiver::query()
            ->where('organization_id', $context->organization()->id)
            ->where('name', $name)
            ->first();

        return $existing ?? $waivers->create(
            $context->organization(),
            $scopeType,
            $scopeId,
            $name,
            $description,
            $expiresAfterDays,
        );
    }
}
