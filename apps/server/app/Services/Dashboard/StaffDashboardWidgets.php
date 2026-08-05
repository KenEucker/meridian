<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardAttention;
use App\Domain\Dashboard\DashboardWidget;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * UI contract 13.1, the staff widgets (M18.28).
 *
 * Everything here is about the reader's own record: the shift they are on now,
 * the ones they are on next, the departments they belong to, the things about
 * those shifts that need them to do something, and the documents they have been
 * asked to acknowledge. No widget in this group reads another person.
 *
 * `staff.quiet_state` is the group's last word rather than a sixth reading. It
 * is compiled only when nothing else on the group is above Routine, so the
 * reassurance is earned — widget spec 14, "quiet states should reassure rather
 * than celebrate" — and never sits on a screen that also carries a warning.
 *
 * One thing this group deliberately does not do is imply a consequence.
 * Outstanding acknowledgments are reported at Attention and no higher, because
 * POL-026 and POL-027 keep acknowledgment out of shift signup and credential
 * eligibility: an outstanding document is something to read, not something
 * blocking the reader from working.
 */
final class StaffDashboardWidgets extends DashboardWidgetCompiler
{
    /** How far ahead a shift counts as "starting soon" for an alert. */
    private const STARTING_SOON_HOURS = 2;

    /** How many upcoming shifts the widget names before deferring to the board. */
    private const UPCOMING_SHOWN = 3;

    public function __construct(private readonly User $user) {}

    /**
     * @return list<DashboardWidget>
     */
    public function compile(Event $event, DashboardAudience $audience, Carbon $now): array
    {
        $assignments = $this->assignments($event, $audience->staffIds);
        $attendance = $this->attendance($event, $audience->staffIds);

        $widgets = [
            $this->currentShift($event, $assignments, $attendance, $now),
            $this->upcomingShifts($event, $assignments, $now),
            $this->assignedDepartments($event, $audience->staffIds),
            $this->shiftAlerts($event, $assignments, $attendance, $now),
            $this->documentAcknowledgments($event, $audience->staffIds),
        ];

        /*
         * "Nothing Needs Action" is about action, not about emptiness.
         *
         * The test is whether any other staff widget carries attention above
         * Routine — not whether they all went quiet. Belonging to a department
         * is a fact rather than a task, and a reassurance that disappeared
         * because somebody is a member of something would be a reassurance
         * almost nobody ever saw.
         */
        $nothingNeedsAction = array_reduce(
            $widgets,
            fn (bool $carry, DashboardWidget $widget): bool => $carry
                && $widget->attention === DashboardAttention::Routine,
            true,
        );

        if ($nothingNeedsAction) {
            $widgets[] = DashboardWidget::reporting(
                definition: $this->definition('staff.quiet_state'),
                attention: DashboardAttention::Routine,
                summary: 'Nothing needs your attention right now.',
            );
        }

        return $widgets;
    }

    /**
     * The shift running now (UI contract 13.1, `staff.current_shift`).
     *
     * A shift is current when the clock is inside its window, not when somebody
     * is checked into it: arriving late is exactly the case the widget exists
     * for, and one that only appeared after check-in would go dark at the moment
     * it had something to say. The attendance state rides along so the card can
     * say which of the two it is.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @param  array<string, AttendanceRecord>  $attendance
     */
    private function currentShift(
        Event $event,
        Collection $assignments,
        array $attendance,
        Carbon $now,
    ): DashboardWidget {
        $current = $assignments->filter(
            fn (ShiftAssignment $assignment): bool => $this->isRunning($assignment->shift, $now),
        )->values();

        if ($current->isEmpty()) {
            return $this->quiet('staff.current_shift');
        }

        $items = $current->map(function (ShiftAssignment $assignment) use ($event, $attendance): array {
            $shift = $assignment->shift;
            $record = $attendance[$this->attendanceKey($assignment)] ?? null;

            return $this->item(
                label: (string) $shift?->title,
                detail: trim(($shift?->department?->name ?? '').' · '.$this->eventTime($event, $shift?->starts_at).'–'.$this->eventTime($event, $shift?->ends_at)),
                status: $this->attendanceLabel($record),
            );
        })->all();

        $awaitingCheckIn = $current->contains(
            fn (ShiftAssignment $assignment): bool => ! $this->hasArrived($attendance[$this->attendanceKey($assignment)] ?? null),
        );

        return DashboardWidget::reporting(
            definition: $this->definition('staff.current_shift'),
            attention: $awaitingCheckIn ? DashboardAttention::Attention : DashboardAttention::Routine,
            summary: $awaitingCheckIn
                ? 'Your shift has started and you are not checked in yet.'
                : 'You are checked in and working.',
            items: $this->capped($items),
        );
    }

    /**
     * @param  Collection<int, ShiftAssignment>  $assignments
     */
    private function upcomingShifts(Event $event, Collection $assignments, Carbon $now): DashboardWidget
    {
        $upcoming = $assignments
            ->filter(fn (ShiftAssignment $assignment): bool => $assignment->shift?->starts_at !== null
                && $assignment->shift->starts_at->greaterThan($now)
                && ! $assignment->shift->isCancelled())
            ->sortBy(fn (ShiftAssignment $assignment): string => (string) $assignment->shift?->starts_at?->toIso8601String())
            ->values();

        if ($upcoming->isEmpty()) {
            return $this->quiet('staff.upcoming_shifts');
        }

        $items = $upcoming
            ->take(self::UPCOMING_SHOWN)
            ->map(fn (ShiftAssignment $assignment): array => $this->item(
                label: (string) $assignment->shift?->title,
                detail: ($assignment->shift?->department?->name ?? '').' · '.$this->eventTime($event, $assignment->shift?->starts_at),
            ))
            ->values()
            ->all();

        return DashboardWidget::reporting(
            definition: $this->definition('staff.upcoming_shifts'),
            attention: DashboardAttention::Routine,
            summary: 'You are on '.$this->plural($upcoming->count(), 'upcoming shift').'.',
            items: $items,
            metric: ['value' => $upcoming->count(), 'label' => 'upcoming shifts'],
        );
    }

    /**
     * The departments the reader belongs to (UI contract 13.1,
     * `staff.assigned_departments`).
     *
     * Active memberships only, and only in the event's own organization. An
     * inactive or emeritus membership is a fact about somebody's history rather
     * than a department they are working in this weekend, and listing one on an
     * event dashboard would read as an assignment.
     *
     * @param  list<string>  $staffIds
     */
    private function assignedDepartments(Event $event, array $staffIds): DashboardWidget
    {
        if ($staffIds === []) {
            return $this->quiet('staff.assigned_departments');
        }

        $departments = Department::query()
            ->where('organization_id', $event->organization_id)
            ->whereHas('memberships', fn (Builder $query) => $query
                ->active()
                ->where('status', DepartmentMembership::STATUS_ACTIVE)
                ->whereIn('staff_id', $staffIds))
            ->orderBy('name')
            ->get();

        if ($departments->isEmpty()) {
            return $this->quiet('staff.assigned_departments');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('staff.assigned_departments'),
            attention: DashboardAttention::Routine,
            summary: 'You are an active member of '.$this->plural($departments->count(), 'department').'.',
            items: $this->capped($departments
                ->map(fn (Department $department): array => $this->item(
                    label: $department->name,
                    detail: $department->code,
                ))
                ->values()
                ->all()),
            metric: ['value' => $departments->count(), 'label' => 'departments'],
        );
    }

    /**
     * What about the reader's own shifts needs them (UI contract 13.1,
     * `staff.shift_alerts`).
     *
     * Four states, each a fact about a shift rather than a judgement about the
     * person: a shift that started with no check-in against it, one starting
     * within the next couple of hours, one they were marked a no-show for, and
     * one that ended with them still checked into it. The last is the quiet
     * damage case — hours keep accruing against an open check-in — and it is the
     * one nobody notices without being told.
     *
     * A cancelled shift somebody is still assigned to is an alert too, because
     * nothing removes the assignment and the reader would otherwise turn up.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @param  array<string, AttendanceRecord>  $attendance
     */
    private function shiftAlerts(
        Event $event,
        Collection $assignments,
        array $attendance,
        Carbon $now,
    ): DashboardWidget {
        $alerts = [];
        $attention = DashboardAttention::Routine;

        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;

            if ($shift === null) {
                continue;
            }

            $record = $attendance[$this->attendanceKey($assignment)] ?? null;
            $when = $this->eventTime($event, $shift->starts_at);

            if ($shift->isCancelled()) {
                $alerts[] = $this->item((string) $shift->title, $when, 'Shift cancelled');
                $attention = $this->raise($attention, DashboardAttention::Warning);

                continue;
            }

            if ($record?->current_state === AttendanceRecord::STATE_NO_SHOW) {
                $alerts[] = $this->item((string) $shift->title, $when, 'Marked no-show');
                $attention = $this->raise($attention, DashboardAttention::Attention);

                continue;
            }

            if ($this->isRunning($shift, $now) && ! $this->hasArrived($record)) {
                $alerts[] = $this->item((string) $shift->title, $when, 'Not checked in');
                $attention = $this->raise($attention, DashboardAttention::Warning);

                continue;
            }

            if ($this->hasEnded($shift, $now)
                && $record?->current_state === AttendanceRecord::STATE_CHECKED_IN) {
                $alerts[] = $this->item(
                    (string) $shift->title,
                    $this->eventTime($event, $shift->ends_at),
                    'Still checked in',
                );
                $attention = $this->raise($attention, DashboardAttention::Warning);

                continue;
            }

            if ($shift->starts_at !== null
                && $shift->starts_at->greaterThan($now)
                && $shift->starts_at->lessThanOrEqualTo($now->copy()->addHours(self::STARTING_SOON_HOURS))) {
                $alerts[] = $this->item((string) $shift->title, $when, 'Starting soon');
                $attention = $this->raise($attention, DashboardAttention::Attention);
            }
        }

        if ($alerts === []) {
            return $this->quiet('staff.shift_alerts');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('staff.shift_alerts'),
            attention: $attention,
            summary: $this->plural(count($alerts), 'shift').' needs your attention.',
            items: $this->capped($alerts),
            metric: ['value' => count($alerts), 'label' => 'alerts'],
        );
    }

    /**
     * Outstanding acknowledgments (UI contract 13.1,
     * `staff.document_acknowledgments`; POL-024, POL-025).
     *
     * A requirement pointing at an unpublished document is absent, matching
     * `GET /api/document-acknowledgments/me`: the acknowledge command refuses
     * one, and listing an item nobody can act on lists a fault as a task.
     *
     * A document that changed since it was acknowledged is *not* outstanding.
     * POL-045 says a new version re-requires nothing, and a dashboard that
     * counted it would re-require it in the only way that matters to the person
     * reading.
     *
     * @param  list<string>  $staffIds
     */
    private function documentAcknowledgments(Event $event, array $staffIds): DashboardWidget
    {
        $outstanding = $this->outstandingRequirements($event, $staffIds);

        if ($outstanding === []) {
            return $this->quiet('staff.document_acknowledgments');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('staff.document_acknowledgments'),
            attention: DashboardAttention::Attention,
            summary: $this->plural(count($outstanding), 'document').' you have been asked to acknowledge.',
            items: $this->capped($outstanding),
            metric: ['value' => count($outstanding), 'label' => 'documents'],
        );
    }

    /**
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    private function outstandingRequirements(Event $event, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        $departmentIds = DepartmentMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->pluck('department_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $requirements = DocumentAcknowledgmentRequirement::query()
            ->active()
            ->where('organization_id', $event->organization_id)
            ->where(function (Builder $query) use ($event, $departmentIds): void {
                $query
                    ->where(fn (Builder $scoped) => $scoped
                        ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION)
                        ->where('scope_id', $event->organization_id))
                    ->orWhere(fn (Builder $scoped) => $scoped
                        ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT)
                        ->whereIn('scope_id', $departmentIds));
            })
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        $acknowledgments = DocumentAcknowledgment::query()
            ->where('user_id', $this->user->getKey())
            ->get();

        $rows = [];

        foreach ($requirements as $requirement) {
            $document = $this->documentFor(
                (string) $requirement->document_type,
                (string) $requirement->document_id,
            );

            if ($document === null || ! $document->isPublished()) {
                continue;
            }

            $accepted = $acknowledgments->first(
                fn (DocumentAcknowledgment $acknowledgment): bool => $acknowledgment->document_type === $requirement->document_type
                    && (string) $acknowledgment->document_id === (string) $requirement->document_id
                    && $acknowledgment->scope_type === $requirement->scope_type
                    && (string) $acknowledgment->scope_id === (string) $requirement->scope_id,
            );

            if ($accepted !== null) {
                continue;
            }

            $rows[] = $this->item(
                label: (string) $document->title,
                detail: $requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT
                    ? (string) Department::query()->find($requirement->scope_id)?->name
                    : 'Organization-wide',
                status: 'Not acknowledged',
            );
        }

        return $rows;
    }

    private function documentFor(string $type, string $id): PolicyDocument|ProcedureDocument|null
    {
        return $type === 'procedure'
            ? ProcedureDocument::query()->find($id)
            : PolicyDocument::query()->find($id);
    }

    /**
     * The reader's live assignments in this event, with their shifts.
     *
     * @param  list<string>  $staffIds
     * @return Collection<int, ShiftAssignment>
     */
    private function assignments(Event $event, array $staffIds): Collection
    {
        if ($staffIds === []) {
            return new Collection;
        }

        return ShiftAssignment::query()
            ->with(['shift.department'])
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->whereHas('shift', fn (Builder $query) => $query->where('event_id', $event->id))
            ->get();
    }

    /**
     * The reader's attendance records in this event, keyed `shiftId:staffId`.
     *
     * @param  list<string>  $staffIds
     * @return array<string, AttendanceRecord>
     */
    private function attendance(Event $event, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        $records = [];

        foreach (
            AttendanceRecord::query()
                ->where('event_id', $event->id)
                ->whereIn('staff_id', $staffIds)
                ->get() as $record
        ) {
            $records[(string) $record->shift_id.':'.(string) $record->staff_id] = $record;
        }

        return $records;
    }

    private function attendanceKey(ShiftAssignment $assignment): string
    {
        return (string) $assignment->shift_id.':'.(string) $assignment->staff_id;
    }

    private function isRunning(?Shift $shift, Carbon $now): bool
    {
        return $shift !== null
            && ! $shift->isCancelled()
            && $shift->starts_at !== null
            && $shift->ends_at !== null
            && $now->greaterThanOrEqualTo($shift->starts_at)
            && $now->lessThanOrEqualTo($shift->ends_at);
    }

    private function hasEnded(Shift $shift, Carbon $now): bool
    {
        return ! $shift->isCancelled()
            && $shift->ends_at !== null
            && $now->greaterThan($shift->ends_at);
    }

    /**
     * Whether somebody arrived, read from the arrival rather than from the state
     * they are in now, so a checked-out shift still counts as attended.
     */
    private function hasArrived(?AttendanceRecord $record): bool
    {
        return $record?->checked_in_at !== null;
    }

    private function attendanceLabel(?AttendanceRecord $record): string
    {
        return match ($record?->current_state) {
            AttendanceRecord::STATE_CHECKED_IN => 'Checked in',
            AttendanceRecord::STATE_CHECKED_OUT => 'Checked out',
            AttendanceRecord::STATE_NO_SHOW => 'No-show',
            AttendanceRecord::STATE_EXCUSED => 'Excused',
            AttendanceRecord::STATE_CORRECTED => 'Corrected',
            default => 'Not checked in',
        };
    }

    private function raise(DashboardAttention $current, DashboardAttention $candidate): DashboardAttention
    {
        return $candidate->rank() < $current->rank() ? $candidate : $current;
    }
}
