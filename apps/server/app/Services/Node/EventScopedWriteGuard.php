<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentListPreset;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event as Events;

/**
 * Refuses local writes to event-scoped records on a node that does not hold
 * event authority (technical spec 10.2).
 *
 * During the active event window the on-site primary node is authoritative for
 * event-scoped records and central is read-only for that event, so an edit made
 * on central has to be refused wherever it is attempted. Meridian has many
 * event-scoped write paths — command endpoints, Orchid repair screens, console
 * commands, and services called from all three — and an authority boundary that
 * each of them has to remember to call is a boundary that will eventually be
 * missed by one of them.
 *
 * This guard therefore listens to Eloquent's model events rather than being
 * invoked per call site. That is deliberately different from the rest of the
 * codebase, which prefers explicit action classes over observers: this is not
 * domain behavior hidden in a model hook, it is a fail-closed boundary in the
 * same spirit as the event-mode checks, and its value comes precisely from not
 * being skippable.
 *
 * What it covers, and what it does not:
 *
 *   - Models carrying `event_id` are guarded by that column. Models that reach
 *     an event through a parent row are guarded through {@see PARENT_SCOPE},
 *     because appending an incident note never touches the incident row and
 *     would otherwise pass unguarded.
 *   - Mass query-builder writes (`Model::query()->update()`/`delete()`) do not
 *     fire model events and are not covered. Meridian writes operational
 *     records through model instances; the few mass deletes it performs are on
 *     child rows whose own creation is guarded.
 *   - {@see EXEMPT} models are this node's own bookkeeping rather than the
 *     event's operational truth. The sync log and the audit trail must stay
 *     writable on a read-only node — refusing them would stop the node from
 *     recording that it refused something — and the `events` row itself stays
 *     writable so an organizer can still close or extend the window that makes
 *     the node read-only in the first place. Incident list presets are one
 *     user's saved view of a list, not an operational record of the event.
 *
 * Operations arriving from the authoritative node run inside
 * {@see withoutEnforcement()}, because data arriving from the on-site primary
 * node is the documented exception to central being read-only. Authority for
 * those is decided once, on receipt, by {@see NodeOperationReceiver}.
 */
class EventScopedWriteGuard
{
    /**
     * Models excluded from event authority even though they carry `event_id`.
     *
     * @var list<class-string<Model>>
     */
    private const EXEMPT = [
        NodeOperation::class,
        AuditEvent::class,
        Node::class,
        Event::class,
        IncidentListPreset::class,
    ];

    /**
     * Event-scoped models that reach their event through a parent row, as
     * `model => [foreign key, parent model]`.
     *
     * @var array<class-string<Model>, array{0: string, 1: class-string<Model>}>
     */
    private const PARENT_SCOPE = [
        IncidentTimelineEntry::class => ['incident_id', Incident::class],
        IncidentFieldReport::class => ['incident_id', Incident::class],
        IncidentStaff::class => ['incident_id', Incident::class],
        IncidentLink::class => ['source_incident_id', Incident::class],
        FieldReportAppend::class => ['field_report_id', FieldReport::class],
        ShiftAssignment::class => ['shift_id', Shift::class],
    ];

    private bool $enforcing = true;

    public function __construct(private readonly EventAuthority $authority) {}

    /**
     * Refuse event-scoped writes on this node from here on.
     */
    public function register(): void
    {
        Events::listen(
            ['eloquent.saving: *', 'eloquent.deleting: *'],
            function (string $eventName, array $payload): void {
                $model = $payload[0] ?? null;

                if ($model instanceof Model) {
                    $this->guard($model);
                }
            },
        );
    }

    /**
     * Run a callback with event authority not enforced, for writes whose
     * authority was already established elsewhere.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutEnforcement(Closure $callback): mixed
    {
        $previous = $this->enforcing;
        $this->enforcing = false;

        try {
            return $callback();
        } finally {
            $this->enforcing = $previous;
        }
    }

    /**
     * @throws EventAuthorityException when this node may not write the record
     */
    public function guard(Model $model): void
    {
        if (! $this->enforcing) {
            return;
        }

        $event = $this->eventFor($model);

        if (! $event instanceof Event) {
            return;
        }

        $this->authority->assertLocalWrite($event, $this->describe($model));
    }

    private function eventFor(Model $model): ?Event
    {
        if (in_array($model::class, self::EXEMPT, true)) {
            return null;
        }

        $eventId = $this->eventIdFor($model);

        if (! is_string($eventId) || $eventId === '') {
            return null;
        }

        return Event::query()->find($eventId);
    }

    private function eventIdFor(Model $model): mixed
    {
        $parentScope = self::PARENT_SCOPE[$model::class] ?? null;

        if ($parentScope === null) {
            return $model->getAttribute('event_id');
        }

        [$foreignKey, $parentModel] = $parentScope;

        $parentId = $model->getAttribute($foreignKey);

        if (! is_string($parentId) || $parentId === '') {
            return null;
        }

        return $parentModel::query()->whereKey($parentId)->value('event_id');
    }

    /**
     * A human-readable name for the record, for the denial message. Model class
     * names are the only names available this deep, so they are converted to
     * words rather than shown as class paths.
     */
    private function describe(Model $model): string
    {
        $name = class_basename($model);

        return str($name)->snake(' ')->lower()->toString();
    }
}
