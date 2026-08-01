<?php

namespace Database\Seeders;

use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\IncidentListPreset;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Application\EventApplicationService;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Incidents\IncidentListPresetService;
use App\Services\Incidents\IncidentSearchFilters;
use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;

/**
 * The way in, and the things that fall out the other end.
 *
 * Applications, credentials, credit policies, and one saved incident list
 * preset. They are grouped because they are all consequences rather than
 * operations: nobody works a shift by approving an application, but the review
 * queue, the credential export, and the credit ledger are all downstream of
 * work that has already happened, and none of them have anything to show unless
 * the scenario has been through the whole cycle first.
 *
 * The application queue is seeded in every terminal state alongside two still
 * waiting, because the queue's whole purpose is deciding, and a queue where
 * every row is already decided cannot be practised on while a queue where none
 * are cannot show what a decision looks like.
 */
class IntakeScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;

        $this->seedApplications($context);
        $this->seedCreditPolicies($context);
        $this->seedCredentials($context);
        $this->seedIncidentPreset($context);
    }

    /**
     * Applications in every state the review queue can hold.
     *
     * Submitted against the upcoming event rather than the running one, because
     * applying to work an event that is already half over is not the case the
     * queue exists for. Pat is the catalog's prospective persona and is left
     * undecided on purpose, so there is always something to approve.
     */
    private function seedApplications(ScenarioContext $context): void
    {
        $applications = app(EventApplicationService::class);
        $event = $context->upcomingEvent();
        $olive = $context->user('olive');
        $rangers = $context->department('RANGERS');

        $definitions = [
            ['Pat Prospective', 'pat.prospective@northwood-collective.test', null],
            ['Wren Waiting', 'wren.waiting@northwood-collective.test', null],
            ['Ada Approved', 'ada.approved@northwood-collective.test', 'approve'],
            ['Rory Rejected', 'rory.rejected@northwood-collective.test', 'reject'],
            ['Del Deferred', 'del.deferred@northwood-collective.test', 'defer'],
        ];

        foreach ($definitions as [$name, $email, $decision]) {
            $existing = EventApplication::query()
                ->where('event_id', $event->id)
                ->where('applicant_email', $email)
                ->first();

            if ($existing !== null) {
                continue;
            }

            $application = $applications->submit(
                $event,
                $name,
                $email,
                null,
                [(string) $rangers->id],
            );

            match ($decision) {
                'approve' => $applications->approve($application, $olive, 'Worked with us last year.'),
                'reject' => $applications->reject($application, $olive, 'No departments have capacity this cycle.'),
                'defer' => $applications->defer($application, $olive, 'Holding for the second placement round.'),
                default => null,
            };
        }
    }

    /**
     * An organization default and one shift override.
     *
     * The resolution rule is that a shift's own policy wins over the
     * organization default (ORG-009, SHIFT-010), and a scenario with only a
     * default can never show it winning. The overnight shift carries the
     * multiplier, which is also the case the requirement was written for.
     */
    private function seedCreditPolicies(ScenarioContext $context): void
    {
        $organization = $context->organization();

        $default = CreditPolicy::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Standard Hour',
            ],
            [
                'event_id' => null,
                'shift_id' => null,
                'credit_multiplier' => 1.0,
            ],
        );

        $overnight = Shift::query()
            ->where('event_id', $context->event()->id)
            ->where('title', 'Overnight Patrol')
            ->first();

        if ($overnight === null) {
            return;
        }

        $nightPolicy = CreditPolicy::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'name' => 'Overnight Multiplier',
            ],
            [
                'event_id' => (string) $context->event()->id,
                'shift_id' => (string) $overnight->id,
                'credit_multiplier' => 1.5,
            ],
        );

        if ($overnight->credit_policy_id === null) {
            $overnight->forceFill(['credit_policy_id' => $nightPolicy->id])->save();
        }

        unset($default);
    }

    /**
     * Credentials, recalculated rather than written.
     *
     * `CredentialEligibilityService` decides who is eligible from the
     * assignments, trainings, waivers, and organization status the rest of the
     * scenario already produced. Writing credential rows directly would produce
     * a set of answers nothing in the product agrees with; recalculating gives
     * the export the same rows the product would.
     */
    private function seedCredentials(ScenarioContext $context): void
    {
        $credentials = app(CredentialEligibilityService::class);
        $event = $context->event();

        foreach ($this->departmentStaff($context, $event) as $staff) {
            $credentials->recalculate($event, $staff, ScenarioClock::now(), $context->user('olive'));
        }
    }

    /**
     * One saved preset, so the IMS list has a named view to apply.
     *
     * Presets are personal, so this one belongs to the IC lead and nobody else
     * can see it — which is itself worth having in the data, because "my presets
     * are mine" is invisible in a database that only holds one person's.
     */
    private function seedIncidentPreset(ScenarioContext $context): void
    {
        $event = $context->event();
        $ingrid = $context->user('ingrid');

        $exists = IncidentListPreset::query()
            ->where('event_id', $event->id)
            ->where('user_id', $ingrid->id)
            ->where('name', 'Open and serious')
            ->exists();

        if ($exists) {
            return;
        }

        app(IncidentListPresetService::class)->save(
            $ingrid,
            $event,
            'Open and serious',
            IncidentSearchFilters::fromQuery([
                'state' => 'active',
                'priority' => 'Serious',
                'sort' => 'started',
                'direction' => 'desc',
            ]),
        );
    }

    /**
     * @return iterable<Staff>
     */
    private function departmentStaff(ScenarioContext $context, Event $event): iterable
    {
        return Staff::query()
            ->whereIn(
                'id',
                DepartmentMembership::query()
                    ->active()
                    ->whereIn(
                        'department_id',
                        Department::query()
                            ->where('organization_id', $context->organization()->id)
                            ->select('id'),
                    )
                    ->select('staff_id'),
            )
            ->get();
    }
}
