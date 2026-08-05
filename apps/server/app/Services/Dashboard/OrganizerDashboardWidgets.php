<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardAttention;
use App\Domain\Dashboard\DashboardWidget;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * UI contract 13.4, the organizer widgets (M18.28).
 *
 * The rule that shapes this file is the one stated under the contract's table:
 * "Organizer widgets must not surface IMS incidents, restricted Field Reports,
 * active incident counts, or incident priority alerts unless the user also has
 * IC team-granted authority."
 *
 * It is kept by construction. Nothing in this compiler queries `incidents`,
 * `incident_timeline_entries`, `field_reports`, or anything derived from them,
 * and the class imports none of those models — so the exclusion cannot be lost
 * to a later edit that forgets a filter. An organizer who also holds IC standing
 * still reads incident data, from {@see IncidentCommandDashboardWidgets}, as an
 * IC user. That is the contract's "unless": the authority arrives through the
 * Incident Command department, never through organizing.
 *
 * The other property here is that an organizer's readiness is about gaps rather
 * than about volume. Widget spec 10 asks a metric to answer why the number
 * matters, so the counts reported are shortfalls, outstanding reviews, and
 * departments with nothing scheduled — the things somebody would do something
 * about — rather than a tally of how much of the event exists.
 */
final class OrganizerDashboardWidgets extends DashboardWidgetCompiler
{
    /**
     * @return list<DashboardWidget>
     */
    public function compile(Event $event, DashboardAudience $audience, Carbon $now): array
    {
        $departments = $this->participatingDepartments($event);
        $shifts = $this->eventShifts($event);
        $assigned = $this->assignmentCounts($shifts);

        return [
            $this->eventReadiness($event, $departments, $shifts, $assigned, $now),
            $this->crossDepartmentCoverage($departments, $shifts, $assigned, $now),
            $this->applicationReview($event),
            $this->policyReadiness($event),
            $this->operationsWindow($event, $now),
        ];
    }

    /**
     * Whether this event is ready to run (UI contract 13.4,
     * `org.event_readiness`).
     *
     * Four gaps, each one somebody can close: no department taking part, a
     * participating department with nothing scheduled, shifts short of people,
     * and no operations window recorded. A quiet answer means none of the four,
     * which is what "Event readiness looks okay" is allowed to mean.
     *
     * @param  Collection<int, Department>  $departments
     * @param  Collection<int, Shift>  $shifts
     * @param  array<string, int>  $assigned
     */
    private function eventReadiness(
        Event $event,
        Collection $departments,
        Collection $shifts,
        array $assigned,
        Carbon $now,
    ): DashboardWidget {
        $items = [];
        $attention = DashboardAttention::Routine;

        if ($departments->isEmpty()) {
            $items[] = $this->item('No departments taking part', 'Nothing can be scheduled until one does', 'Blocking');
            $attention = DashboardAttention::Warning;
        }

        $scheduled = $shifts->groupBy(fn (Shift $shift): string => (string) $shift->department_id);

        foreach ($departments as $department) {
            if (! $scheduled->has((string) $department->id)) {
                $items[] = $this->item($department->name, 'Taking part with no shifts scheduled', 'No shifts');
                $attention = $this->raise($attention, DashboardAttention::Attention);
            }
        }

        $shortfall = $shifts
            ->filter(fn (Shift $shift): bool => $shift->capacity !== null
                && ! $this->hasFinished($shift, $now)
                && ($assigned[(string) $shift->id] ?? 0) < $shift->capacity)
            ->sum(fn (Shift $shift): int => (int) $shift->capacity - ($assigned[(string) $shift->id] ?? 0));

        if ($shortfall > 0) {
            $items[] = $this->item(
                'Unfilled shift places',
                'Across every participating department',
                $this->plural((int) $shortfall, 'place').' short',
            );
            $attention = $this->raise($attention, DashboardAttention::Attention);
        }

        if ($event->active_event_window_starts_at === null && $event->active_event_window_ends_at === null) {
            $items[] = $this->item(
                'No operations window recorded',
                'Event authority and governance freezes both read this window',
                'Not set',
            );
            $attention = $this->raise($attention, DashboardAttention::Attention);
        }

        if ($items === []) {
            return $this->quiet('org.event_readiness');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('org.event_readiness'),
            attention: $attention,
            summary: $this->plural(count($items), 'readiness gap').' to close before this event runs.',
            items: $this->capped($items),
            metric: ['value' => count($items), 'label' => 'gaps'],
        );
    }

    /**
     * Where the shortfall is (UI contract 13.4, `org.cross_dept_coverage`).
     *
     * The same arithmetic as a department lead's coverage widget, reported one
     * department at a time. That is the whole difference between 13.2 and this
     * row: a lead needs the shift, an organizer needs to know which department
     * to ask about it.
     *
     * @param  Collection<int, Department>  $departments
     * @param  Collection<int, Shift>  $shifts
     * @param  array<string, int>  $assigned
     */
    private function crossDepartmentCoverage(
        Collection $departments,
        Collection $shifts,
        array $assigned,
        Carbon $now,
    ): DashboardWidget {
        $items = [];
        $total = 0;

        foreach ($departments as $department) {
            $short = $shifts
                ->filter(fn (Shift $shift): bool => (string) $shift->department_id === (string) $department->id
                    && $shift->capacity !== null
                    && ! $this->hasFinished($shift, $now)
                    && ($assigned[(string) $shift->id] ?? 0) < $shift->capacity);

            if ($short->isEmpty()) {
                continue;
            }

            $places = (int) $short->sum(
                fn (Shift $shift): int => (int) $shift->capacity - ($assigned[(string) $shift->id] ?? 0),
            );
            $total += $places;

            $items[] = $this->item(
                label: $department->name,
                detail: $this->plural($short->count(), 'shift').' short',
                status: $this->plural($places, 'place').' unfilled',
            );
        }

        if ($items === []) {
            return $this->quiet('org.cross_dept_coverage');
        }

        usort($items, fn (array $left, array $right): int => strcmp((string) $left['label'], (string) $right['label']));

        return DashboardWidget::reporting(
            definition: $this->definition('org.cross_dept_coverage'),
            attention: DashboardAttention::Attention,
            summary: $this->plural(count($items), 'department').' short of staff, '.$this->plural($total, 'place').' in total.',
            items: $this->capped($items),
            metric: ['value' => $total, 'label' => 'unfilled places'],
        );
    }

    /**
     * Applications waiting on somebody (UI contract 13.4,
     * `org.application_review`; APP-005).
     *
     * Submitted and deferred both count. A deferred application is one somebody
     * decided to come back to, and an event that never comes back to it has
     * simply rejected it slowly.
     */
    private function applicationReview(Event $event): DashboardWidget
    {
        $pending = EventApplication::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                EventApplication::STATUS_SUBMITTED,
                EventApplication::STATUS_DEFERRED,
            ])
            ->get();

        if ($pending->isEmpty()) {
            return $this->quiet('org.application_review');
        }

        $deferred = $pending->where('status', EventApplication::STATUS_DEFERRED)->count();
        $submitted = $pending->count() - $deferred;

        return DashboardWidget::reporting(
            definition: $this->definition('org.application_review'),
            attention: DashboardAttention::Attention,
            summary: $this->plural($pending->count(), 'application').' awaiting review'
                .($deferred > 0 ? ', '.$deferred.' of them deferred' : '').'.',
            items: array_values(array_filter([
                $submitted > 0 ? $this->item('Submitted', 'Not yet decided', (string) $submitted) : null,
                $deferred > 0 ? $this->item('Deferred', 'Set aside for a later decision', (string) $deferred) : null,
            ])),
            metric: ['value' => $pending->count(), 'label' => 'awaiting review'],
        );
    }

    /**
     * Organization documents somebody was asked to acknowledge and has not
     * (UI contract 13.4, `org.policy_readiness`).
     *
     * Organization scope only. A department's own requirements are its lead's
     * readiness (13.2), and carrying them here would put the same gap on two
     * screens with neither owning it.
     */
    private function policyReadiness(Event $event): DashboardWidget
    {
        $requirements = DocumentAcknowledgmentRequirement::query()
            ->active()
            ->where('organization_id', $event->organization_id)
            ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION)
            ->where('scope_id', $event->organization_id)
            ->get();

        if ($requirements->isEmpty()) {
            return $this->quiet('org.policy_readiness');
        }

        $staffIds = DepartmentMembership::query()
            ->active()
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->whereHas('department', fn (Builder $query) => $query
                ->where('organization_id', $event->organization_id))
            ->pluck('staff_id')
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $items = [];
        $outstanding = 0;

        foreach ($requirements as $requirement) {
            $document = $this->documentFor(
                (string) $requirement->document_type,
                (string) $requirement->document_id,
            );

            if ($document === null) {
                continue;
            }

            if (! $document->isPublished()) {
                $items[] = $this->item((string) $document->title, 'Required organization-wide', 'Document not published');

                continue;
            }

            if ($staffIds->isEmpty()) {
                continue;
            }

            $acknowledged = DocumentAcknowledgment::query()
                ->where('document_type', $requirement->document_type)
                ->where('document_id', $requirement->document_id)
                ->where('scope_type', $requirement->scope_type)
                ->where('scope_id', $requirement->scope_id)
                ->whereIn('staff_id', $staffIds->all())
                ->pluck('staff_id')
                ->map(fn ($id): string => (string) $id)
                ->unique();

            $short = $staffIds->reject(fn (string $id): bool => $acknowledged->contains($id))->count();

            if ($short === 0) {
                continue;
            }

            $outstanding += $short;
            $items[] = $this->item(
                label: (string) $document->title,
                detail: 'Required organization-wide',
                status: $this->plural($short, 'staff member').' have not acknowledged',
            );
        }

        if ($items === []) {
            return $this->quiet('org.policy_readiness');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('org.policy_readiness'),
            attention: DashboardAttention::Attention,
            summary: $this->plural(count($items), 'organization document').' still outstanding.',
            items: $this->capped($items),
            metric: ['value' => $outstanding, 'label' => 'acknowledgments outstanding'],
        );
    }

    /**
     * Where the event is against its own window (UI contract 13.4,
     * `org.operations_window`).
     *
     * The contract's quiet state for this row is "Event outside operations
     * window", which is the one place in the inventory where quiet means "not
     * happening" rather than "nothing wrong". So the widget reports while the
     * window is open and goes quiet when it is not: during the window, edits
     * freeze, the on-site node holds authority, and an organizer needs to know
     * that; outside it, there is nothing to say.
     */
    private function operationsWindow(Event $event, Carbon $now): DashboardWidget
    {
        $starts = $event->active_event_window_starts_at;
        $ends = $event->active_event_window_ends_at;

        $open = ($starts === null || $now->greaterThanOrEqualTo($starts))
            && ($ends === null || $now->lessThanOrEqualTo($ends))
            && ! ($starts === null && $ends === null);

        if (! $open) {
            return $this->quiet('org.operations_window');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('org.operations_window'),
            attention: DashboardAttention::Routine,
            summary: 'This event is inside its active operations window.',
            items: [
                $this->item(
                    'Active window',
                    ($this->eventTime($event, $starts) ?? 'Start not set')
                        .' to '.($this->eventTime($event, $ends) ?? 'end not set'),
                    'Open',
                ),
                $this->item(
                    'Governance edits',
                    'Branding, policy, and configuration edits are refused for the duration',
                    'Frozen',
                ),
            ],
        );
    }

    /**
     * @return Collection<int, Department>
     */
    private function participatingDepartments(Event $event): Collection
    {
        return Department::query()
            ->whereHas('eventAssignments', fn (Builder $query) => $query
                ->where('event_id', $event->id)
                ->whereNull('archived_at'))
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Shift>
     */
    private function eventShifts(Event $event): Collection
    {
        return Shift::query()->where('event_id', $event->id)->active()->get();
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array<string, int>
     */
    private function assignmentCounts(Collection $shifts): array
    {
        $ids = $shifts->map(fn (Shift $shift): string => (string) $shift->id)->all();

        if ($ids === []) {
            return [];
        }

        return ShiftAssignment::query()
            ->active()
            ->whereIn('shift_id', $ids)
            ->get()
            ->groupBy(fn (ShiftAssignment $assignment): string => (string) $assignment->shift_id)
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    private function hasFinished(Shift $shift, Carbon $now): bool
    {
        return $shift->ends_at !== null && $shift->ends_at->lessThan($now);
    }

    private function documentFor(string $type, string $id): PolicyDocument|ProcedureDocument|null
    {
        return $type === 'procedure'
            ? ProcedureDocument::query()->find($id)
            : PolicyDocument::query()->find($id);
    }

    private function raise(DashboardAttention $current, DashboardAttention $candidate): DashboardAttention
    {
        return $candidate->rank() < $current->rank() ? $candidate : $current;
    }
}
