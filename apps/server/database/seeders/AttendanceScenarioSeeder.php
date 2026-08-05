<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Deployment;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInService;
use App\Services\Attendance\AttendanceCheckOutService;
use App\Services\Attendance\AttendanceMarkNoShowService;
use App\Services\Attendance\HoursCorrectionService;
use App\Services\Deployments\DeploymentAssignmentService;
use App\Services\Equipment\EquipmentCheckoutService;
use App\Services\Presence\DepartmentPresenceService;
use App\Services\Shift\ShiftAssignmentService;
use App\Services\Shift\ShiftSignupService;
use Database\Seeders\Support\ScenarioClock;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Who is where, who worked what, and what the desk is still owed.
 *
 * This is the seeder the operational surfaces actually render, and it is built
 * so that every branch those surfaces can take has somebody standing in it at
 * the same time. That is the part a smaller scenario cannot do: most of the
 * Logistics Desk's behaviour is a property of a person rather than of a role, so
 * proving check-in works needs somebody not yet checked in, proving check-out
 * works needs somebody who is, and proving the off-site block works needs one
 * person held by a shift and another held by a radio. One persona cannot be in
 * two of those states, so the Dirt crew is four people in four states.
 *
 * The order below is the order the desk works in and it is not interchangeable:
 * presence first, because a check-in is refused for anybody not marked on-site;
 * assignments next, because attendance is recorded against one; then check-ins,
 * check-outs, equipment, and deployments. Freezing and correcting hours comes
 * last, because both act on records the check-outs above have to have created.
 *
 * Everything runs through the domain services, so the attendance history is
 * append-only the way the product writes it — real operations with real
 * provenance, a real audit trail, and hours computed by the same arithmetic the
 * product uses rather than typed in.
 */
class AttendanceScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $context = new ScenarioContext;
        $event = $context->event();
        $rangers = $context->department('RANGERS');

        // Marked by the desk, so the presence history reads as somebody's work.
        $this->markOnSite($context, ['vera', 'nora', 'felix', 'quinn', 'sam', 'ingrid', 'mira'], 'RANGERS');
        $this->markOnSite($context, ['gabe'], 'GATE');
        $this->markOnSite($context, ['dex'], 'DPW');

        $sunrise = $this->shift($event, 'Sunrise Patrol');
        $morning = $this->shift($event, 'Morning Patrol');
        $day = $this->shift($event, 'Day Patrol');
        $swing = $this->shift($event, 'Swing Patrol');
        $commandWatch = $this->shift($event, 'Command Day Watch');
        $gateMorning = $this->shift($event, 'Gate Morning');
        $gateAfternoon = $this->shift($event, 'Gate Afternoon');
        $loadIn = $this->shift($event, 'Load In');

        /*
         * Sam rather than Dana, and this is exactly the kind of thing the
         * persona split is for. Presence, attendance, equipment handoff, and
         * deployments all resolve through the operational grants — presence
         * needs `department.presence.manage` specifically — and Dana is the
         * department *lead*, which is a different set. Using her here worked
         * only while every Dirt member inherited every grant, which is the bug
         * that split fixed.
         */
        /*
         * Two actors, because the product draws the line between them.
         *
         * Sam holds the operational grants — presence needs
         * `department.presence.manage` specifically, and attendance, equipment
         * handoff, and deployments resolve the same way. Dana is the department
         * *lead*, and rostering somebody onto a shift is lead authority
         * (SHIFT-015) that Sam does not have.
         *
         * Using one person for both worked only while every Dirt member
         * inherited every grant, which is the bug the authority-team split
         * fixed. Gabe and Dex hold both sets for their own departments, so they
         * do both jobs there.
         */
        $sam = $context->user('sam');
        $dana = $context->user('dana');
        $gabe = $context->user('gabe');
        $dex = $context->user('dex');

        $this->assign($context, $sunrise, ['vera', 'nora'], $dana);
        $this->assign($context, $morning, ['nora', 'felix', 'quinn'], $dana);
        $this->assign($context, $day, ['vera', 'sam', 'felix'], $dana);
        $this->assign($context, $swing, ['nora'], $dana);
        $this->assign($context, $commandWatch, ['mira', 'ingrid'], $dana);
        $this->assign($context, $gateMorning, ['gabe'], $gabe);
        $this->assign($context, $gateAfternoon, ['gabe'], $gabe);
        $this->assign($context, $loadIn, ['dex'], $dex);

        /*
         * A shift that is over and settled. Both of these are checked out, and
         * their hours are frozen at the end of the run, which is what puts the
         * "grace period closed" refusal on a card somebody can actually open.
         */
        $this->workedShift($context, $sunrise, 'vera', $sam, minutesLate: 5);
        $this->workedShift($context, $sunrise, 'nora', $sam, minutesLate: 0);

        /*
         * A shift that ended within the hour, and the three ways that goes.
         * Nora worked it and her hours are still inside the correction window —
         * this is the record hours correction is exercised on. Felix never
         * arrived and was marked no-show. Quinn was on the roster and simply has
         * nothing recorded, which is the card that has to say the check-out is
         * what is missing rather than offering a correction.
         */
        $this->workedShift($context, $morning, 'nora', $sam, minutesLate: 12);
        $this->markNoShow($context, $morning, 'felix', $sam);

        /*
         * The shift running now. Vera and Sam are on it and checked in, so both
         * can be checked out and neither can be marked off-site. Felix is on the
         * roster and has not arrived, so his card offers check-in and no-show.
         * Nora is on-site and on no shift at all, which is the one case an
         * unscheduled addition exists for (SLB-008).
         */
        $this->checkIn($context, $day, 'vera', $sam, ScenarioClock::hoursAgo(2));
        $this->checkIn($context, $day, 'sam', $sam, ScenarioClock::hoursAgo(1.5));
        $this->checkIn($context, $commandWatch, 'mira', $sam, ScenarioClock::hoursAgo(1));
        $this->checkIn($context, $gateAfternoon, 'gabe', $gabe, ScenarioClock::now());

        $this->workedShift($context, $gateMorning, 'gabe', $gabe, minutesLate: 3);

        $this->handOutEquipment($context, $day);
        $this->seedDeployments($context, $day);
        $this->seedUpcomingSignups($context);
        $this->settleHours($context, $sunrise, $morning);
    }

    /**
     * @param  list<string>  $personaKeys
     */
    private function markOnSite(ScenarioContext $context, array $personaKeys, string $departmentCode): void
    {
        $presence = app(DepartmentPresenceService::class);
        $department = $context->department($departmentCode);
        $actor = $context->user($departmentCode === 'RANGERS' ? 'sam' : ($departmentCode === 'GATE' ? 'gabe' : 'dex'));

        foreach ($personaKeys as $key) {
            $presence->markOnSite(
                $context->event(),
                $department,
                $context->staff($key),
                $actor,
                ScenarioClock::hoursAgo(11),
            );
        }
    }

    /**
     * @param  list<string>  $personaKeys
     */
    private function assign(ScenarioContext $context, Shift $shift, array $personaKeys, User $assigner): void
    {
        $assignments = app(ShiftAssignmentService::class);

        foreach ($personaKeys as $key) {
            $staff = $context->staff($key);

            if ($this->assignmentFor($shift, $staff) !== null) {
                continue;
            }

            $assignments->assignStaffToShift($shift, $staff, $assigner, $shift->starts_at?->copy()->subDay());
        }
    }

    /**
     * Check somebody in and back out again, leaving canonical hours behind.
     *
     * The late arrival is the point of `minutesLate`: hours worked are the
     * actual window rather than the scheduled one (HOURS-001), and a scenario
     * where every check-in lands exactly on the hour makes the two
     * indistinguishable and the Planning Table's variance column always zero.
     */
    private function workedShift(
        ScenarioContext $context,
        Shift $shift,
        string $personaKey,
        User $actor,
        int $minutesLate,
    ): void {
        $staff = $context->staff($personaKey);
        $startedAt = $shift->starts_at->copy()->addMinutes($minutesLate);
        $endedAt = $shift->ends_at->copy();

        $this->checkIn($context, $shift, $personaKey, $actor, $startedAt);

        $record = AttendanceRecord::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($record !== null && $record->current_state === AttendanceRecord::STATE_CHECKED_OUT) {
            return;
        }

        app(AttendanceCheckOutService::class)->checkOut(
            shift: $shift,
            staff: $staff,
            actor: $actor,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: $startedAt,
            actualEndedAt: $endedAt,
            deviceCreatedAt: $endedAt,
            originDevice: $context->device(),
            originNode: $context->node(),
        );
    }

    private function checkIn(
        ScenarioContext $context,
        Shift $shift,
        string $personaKey,
        User $actor,
        Carbon $at,
    ): void {
        $staff = $context->staff($personaKey);

        $existing = AttendanceRecord::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($existing !== null && $existing->checked_in_at !== null) {
            return;
        }

        app(AttendanceCheckInService::class)->checkIn(
            shift: $shift,
            staff: $staff,
            actor: $actor,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $at,
            originDevice: $context->device(),
            originNode: $context->node(),
        );
    }

    private function markNoShow(ScenarioContext $context, Shift $shift, string $personaKey, User $actor): void
    {
        $staff = $context->staff($personaKey);

        $existing = AttendanceRecord::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($existing !== null && $existing->current_state === AttendanceRecord::STATE_NO_SHOW) {
            return;
        }

        app(AttendanceMarkNoShowService::class)->markNoShow(
            shift: $shift,
            staff: $staff,
            actor: $actor,
            operationUuid: (string) Str::uuid(),
            deviceCreatedAt: $shift->starts_at->copy()->addMinutes(45),
            originDevice: $context->device(),
            originNode: $context->node(),
        );
    }

    /**
     * Radios into hands, in the two shapes the desk has to tell apart.
     *
     * Sam's radio is signed out against the running shift, so it is kit that
     * comes back when that shift ends and it holds him on site until it does.
     * Quinn's is signed out against the event with no shift behind it, and is
     * then written off as missing — the department is still owed it and it stays
     * in his workspace, but SLB-018 stops it being a reason to keep him here.
     *
     * A handful of pooled vests goes out beside them (EQUIP-011, EQUIP-016), so
     * the scenario has a pool with some of it in somebody's hands: its available
     * quantity is visibly below its total, it is not stored `checked_out`, and
     * the partial-return path has a subject to work on.
     */
    private function handOutEquipment(ScenarioContext $context, Shift $day): void
    {
        $checkouts = app(EquipmentCheckoutService::class);
        $rangers = $context->department('RANGERS');
        $sam = $context->user('sam');

        $shiftRadio = $this->equipment($rangers, 'RDO-12');
        $eventRadio = $this->equipment($rangers, 'RDO-09');
        $vest = $this->equipment($rangers, 'VST-01');

        if ($shiftRadio !== null && $shiftRadio->status === EquipmentItem::STATUS_AVAILABLE) {
            $checkouts->checkoutEquipment(
                $shiftRadio,
                $context->staff('sam'),
                $sam,
                $day,
                ScenarioClock::hoursAgo(1.5),
            );
        }

        if ($vest !== null && $vest->status === EquipmentItem::STATUS_AVAILABLE) {
            $checkouts->checkoutEquipment(
                $vest,
                $context->staff('vera'),
                $sam,
                $day,
                ScenarioClock::hoursAgo(2),
            );
        }

        if ($eventRadio !== null && $eventRadio->status === EquipmentItem::STATUS_AVAILABLE) {
            $checkouts->checkoutEquipment(
                $eventRadio,
                $context->staff('quinn'),
                $sam,
                null,
                ScenarioClock::hoursAgo(9),
            );

            // Written off while still out, which is the SLB-018 exception. Set
            // directly rather than through the inventory service, which refuses
            // a state change on an item with an open checkout — that refusal is
            // the product's, and the God Mode write-off path is what this stands
            // in for.
            $eventRadio->forceFill(['status' => EquipmentItem::STATUS_MISSING])->save();
        }

        $vestPool = EquipmentItem::query()
            ->where('department_id', $rangers->id)
            ->pooled()
            ->where('name', 'Hi-vis vest (pooled)')
            ->first();

        if ($vestPool !== null && $vestPool->availableQuantity() === (int) $vestPool->quantity_total) {
            $checkouts->checkoutEquipment(
                equipmentItem: $vestPool,
                staff: $context->staff('vera'),
                actor: $sam,
                shift: $day,
                checkedOutAt: ScenarioClock::hoursAgo(2),
                quantity: 4,
            );
        }
    }

    /**
     * Deployment options, and two people standing in them.
     *
     * The Operations Center's whole job is moving somebody from one of these to
     * another, and it needs at least two to move between plus somebody already
     * placed to make the change visible.
     */
    private function seedDeployments(ScenarioContext $context, Shift $day): void
    {
        $event = $context->event();
        $rangers = $context->department('RANGERS');

        $options = [];

        foreach (
            [
                ['Gate 1', 'Main entry checkpoint', 'North entry, under the arch'],
                ['Perimeter North', 'Fence line sweep', 'From the arch to the north berm'],
                ['Medical Tent', 'Standby with the medics', 'Centre camp, east side'],
            ] as [$name, $description, $location]
        ) {
            $options[$name] = Deployment::query()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'department_id' => $rangers->id,
                    'name' => $name,
                ],
                [
                    'description' => $description,
                    'location_details' => $location,
                ],
            );
        }

        $deployments = app(DeploymentAssignmentService::class);
        $sam = $context->user('sam');

        foreach ([['vera', 'Gate 1'], ['sam', 'Perimeter North']] as [$personaKey, $deploymentName]) {
            $deployments->setCurrentDeployment(
                $day,
                $context->staff($personaKey),
                $options[$deploymentName],
                $sam,
                ScenarioClock::hoursAgo(1),
            );
        }
    }

    /**
     * Signups on the event nobody has started working.
     *
     * Vera holds one she can withdraw from, and Nora fills the single-seat
     * teardown shift so the board has a row that is genuinely full rather than
     * one that only claims to be.
     */
    private function seedUpcomingSignups(ScenarioContext $context): void
    {
        $signups = app(ShiftSignupService::class);
        $upcoming = $context->upcomingEvent();

        foreach ([['Decompression Setup', 'vera'], ['Decompression Teardown', 'nora']] as [$title, $personaKey]) {
            $shift = $this->shift($upcoming, $title);
            $staff = $context->staff($personaKey);

            if ($this->assignmentFor($shift, $staff) !== null) {
                continue;
            }

            $signups->signUp($shift, $staff, $context->user($personaKey));
        }
    }

    /**
     * Close the books on the shift that is over, and leave the recent one open.
     *
     * The two states hours can be in are the two refusals the desk has to show,
     * and a scenario with only one of them can only prove half of it. Sunrise is
     * frozen, so its cards refuse correction and name the moment the grace
     * period closed (HOURS-008). Morning is open and carries one correction
     * already applied, so the audit trail has a before and an after in it before
     * anybody touches the screen.
     */
    private function settleHours(ScenarioContext $context, Shift $sunrise, Shift $morning): void
    {
        $corrections = app(HoursCorrectionService::class);
        $sam = $context->user('sam');

        foreach (HoursWorked::query()->where('shift_id', $sunrise->id)->get() as $hours) {
            if ($hours->frozen_at === null) {
                $corrections->freezeHours($hours, $sam, ScenarioClock::hoursAgo(2));
            }
        }

        $recent = HoursWorked::query()
            ->where('shift_id', $morning->id)
            ->where('staff_id', $context->staff('nora')->id)
            ->first();

        if ($recent === null || $recent->corrected_by_user_id !== null || $recent->frozen_at !== null) {
            return;
        }

        // The desk's own correction: Nora was logged twelve minutes late and
        // said she was on time, so the start moves back to the scheduled one.
        $corrections->correctHours(
            hoursWorked: $recent,
            actor: $sam,
            operationUuid: (string) Str::uuid(),
            actualStartedAt: $morning->starts_at->copy(),
            actualEndedAt: $recent->actual_ended_at->copy(),
            deviceCreatedAt: ScenarioClock::hoursAgo(0.5),
            originDevice: $context->device(),
            originNode: $context->node(),
        );
    }

    private function shift(Event $event, string $title): Shift
    {
        return Shift::query()
            ->where('event_id', $event->id)
            ->where('title', $title)
            ->firstOrFail();
    }

    private function assignmentFor(Shift $shift, Staff $staff): ?ShiftAssignment
    {
        return ShiftAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->whereNull('removed_at')
            ->first();
    }

    private function equipment(Department $department, string $assetTag): ?EquipmentItem
    {
        return EquipmentItem::query()
            ->where('department_id', $department->id)
            ->where('asset_tag', $assetTag)
            ->first();
    }
}
