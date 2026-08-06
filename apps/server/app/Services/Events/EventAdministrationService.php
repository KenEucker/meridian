<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create and edit an organization's events from the product surface (M18.29;
 * UI contract 12.6 `organizer.events`; ORG-006; data/API 10.2).
 *
 * Event administration existed only in the God Mode console, which made
 * declaring when an event runs — the fact the active event window freeze, the
 * hours grace period, and credential eligibility are all measured from —
 * reachable only through the repair interface. This is the same record and the
 * same Incident Command validation, answering to the catalog rather than to
 * `platform.events`.
 *
 * Which department may be Incident Command is not decided here.
 * {@see IncidentCommandDepartmentSelectionService::configureEventOverride()}
 * already enforces ORG-006 — active, in this organization, and actively
 * assigned to the event — and audits the change on its own, so the override is
 * applied through it rather than by writing the column.
 *
 * **Event authority deliberately does not refuse these writes.** Every other
 * event-scoped record moves to the on-site primary node during the active event
 * window, but {@see \App\Services\Node\EventScopedWriteGuard} exempts the
 * `events` row itself, "so an organizer can still close or extend the window
 * that makes the node read-only in the first place" (M12.6). Refusing an event
 * edit here would take that back one surface at a time and leave a window
 * nobody could close from the node they are standing at. The read reports the
 * event's phase and the node that holds authority for its other records, so an
 * organizer editing an event mid-window knows what else is happening elsewhere;
 * it is context, not a gate.
 *
 * Archiving an event is not offered here. Nothing in Alpha 1 asks the product
 * surface for it, and an archived event is how an organization stops running
 * one rather than how it corrects one, so it stays where it already is: the
 * God Mode console.
 */
final class EventAdministrationService
{
    /** The fields the product surface writes, in the order the surface shows them. */
    private const EDITABLE_ATTRIBUTES = [
        'name',
        'slug',
        'timezone',
        'minimum_staff_age',
        'starts_at',
        'ends_at',
        'active_event_window_starts_at',
        'active_event_window_ends_at',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly IncidentCommandDepartmentSelectionService $incidentCommand,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        Organization $organization,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Event {
        $values = $this->normalize($attributes);

        $this->assertSlugAvailable($organization, (string) $values['slug']);

        $event = DB::transaction(function () use ($organization, $values, $actor, $sourceContext): Event {
            $event = Event::query()->create([
                'organization_id' => $organization->getKey(),
                ...$values,
            ])->refresh();

            $this->audit->recordForEntity(
                entity: $event,
                action: 'event.created',
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                eventId: (string) $event->getKey(),
                after: $this->snapshot($event),
                sourceContext: $sourceContext,
            );

            return $event;
        });

        return $this->applyIncidentCommandOverride($event, $attributes, $actor, $sourceContext);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        Event $event,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Event {
        $values = $this->normalize($attributes);

        $this->assertSlugAvailable($event->organization, (string) $values['slug'], $event);

        $event = DB::transaction(function () use ($event, $values, $actor, $sourceContext): Event {
            $before = $this->snapshot($event);

            $event->forceFill($values)->save();
            $event->refresh();

            $after = $this->snapshot($event);

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $event,
                    action: 'event.updated',
                    actorUser: $actor,
                    organizationId: (string) $event->organization_id,
                    eventId: (string) $event->getKey(),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $event;
        });

        return $this->applyIncidentCommandOverride($event, $attributes, $actor, $sourceContext);
    }

    /**
     * The ORG-006 override, applied only when the request carried the key.
     *
     * A request that never mentioned Incident Command leaves the designation
     * alone; a request carrying an explicit null clears it. That distinction is
     * the difference between saving the schedule and unassigning the department
     * that runs the event's incidents.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyIncidentCommandOverride(
        Event $event,
        array $attributes,
        User $actor,
        string $sourceContext,
    ): Event {
        if (! array_key_exists('ic_department_id', $attributes)) {
            return $event;
        }

        $departmentId = $attributes['ic_department_id'];
        $department = $departmentId === null || $departmentId === ''
            ? null
            : Department::query()->find((string) $departmentId);

        if ($department === null && $departmentId !== null && $departmentId !== '') {
            throw EventAdministrationException::invalid(
                'That Incident Command department does not exist.',
            );
        }

        try {
            return $this->incidentCommand->configureEventOverride(
                event: $event,
                department: $department,
                actor: $actor,
                sourceContext: $sourceContext,
            );
        } catch (InvalidArgumentException $exception) {
            throw EventAdministrationException::invalid($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $slug = trim((string) ($attributes['slug'] ?? ''));
        $timezone = trim((string) ($attributes['timezone'] ?? ''));

        if ($name === '' || $slug === '' || $timezone === '') {
            throw EventAdministrationException::invalid(
                'An event needs a name, a slug, and a timezone.',
            );
        }

        $values = [
            'name' => $name,
            'slug' => $slug,
            'timezone' => $timezone,
            'minimum_staff_age' => $this->normalizeAge($attributes['minimum_staff_age'] ?? null),
            'starts_at' => $this->normalizeMoment($attributes['starts_at'] ?? null),
            'ends_at' => $this->normalizeMoment($attributes['ends_at'] ?? null),
            'active_event_window_starts_at' => $this->normalizeMoment(
                $attributes['active_event_window_starts_at'] ?? null,
            ),
            'active_event_window_ends_at' => $this->normalizeMoment(
                $attributes['active_event_window_ends_at'] ?? null,
            ),
        ];

        $this->assertOrdered($values['starts_at'], $values['ends_at'], 'The event cannot end before it starts.');
        $this->assertOrdered(
            $values['active_event_window_starts_at'],
            $values['active_event_window_ends_at'],
            'The active event window cannot end before it starts.',
        );

        return $values;
    }

    private function normalizeAge(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $age = (int) $value;

        if ($age < 0 || $age > 130) {
            throw EventAdministrationException::invalid(
                'A minimum staff age is a whole number of years between 0 and 130.',
            );
        }

        return $age;
    }

    private function normalizeMoment(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw EventAdministrationException::invalid(
                sprintf('"%s" is not a date and time this node can read.', (string) $value),
            );
        }
    }

    private function assertOrdered(?CarbonImmutable $start, ?CarbonImmutable $end, string $message): void
    {
        if ($start !== null && $end !== null && $end->lessThan($start)) {
            throw EventAdministrationException::invalid($message);
        }
    }

    /**
     * One slug per organization, which is what the schema already enforces
     * (`events` unique on organization_id + slug). Caught here so an organizer
     * reading the refusal is told which event already holds the address rather
     * than meeting a database constraint.
     */
    private function assertSlugAvailable(?Organization $organization, string $slug, ?Event $ignore = null): void
    {
        if ($organization === null) {
            return;
        }

        $existing = Event::query()
            ->where('organization_id', $organization->getKey())
            ->where('slug', $slug)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        if ($existing instanceof Event) {
            throw EventAdministrationException::invalid(sprintf(
                'The address "%s" already belongs to "%s" in this organization.',
                $slug,
                (string) $existing->name,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Event $event): array
    {
        $snapshot = [];

        foreach (self::EDITABLE_ATTRIBUTES as $attribute) {
            $value = $event->getAttribute($attribute);

            $snapshot[$attribute] = $value instanceof \DateTimeInterface
                ? CarbonImmutable::instance($value)->toIso8601String()
                : $value;
        }

        return $snapshot;
    }
}
