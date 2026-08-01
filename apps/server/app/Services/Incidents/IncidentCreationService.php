<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentCreationException;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\IncidentType;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates event-specific incident records and assigns chronological IMS numbers
 * (INC-001, INC-003, INC-004; technical spec 19.4; data/API 4.4 and 10.16).
 *
 * Permission checks happen before this service is called by command transport.
 * Attachments and sync projections are later M11 tasks.
 */
final class IncidentCreationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly IncidentTimelineService $timeline,
        private readonly IncidentTypeResolver $incidentTypes,
    ) {}

    /**
     * @param  array{
     *     event_id: string,
     *     title?: string|null,
     *     status?: string|null,
     *     priority_label?: string|null,
     *     started_at?: DateTimeInterface|string|null,
     *     location_name?: string|null,
     *     location_address?: string|null,
     *     location_details?: string|null,
     *     camp_id?: string|null,
     *     map_location_id?: string|null,
     *     incident_type_names?: list<string>|null,
     *     responder_staff_ids?: list<string>|null,
     *     initial_field_update_fields?: list<string>|null
     * }  $attributes
     */
    public function create(
        array $attributes,
        User $actor,
        ?DateTimeInterface $createdAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): Incident {
        return DB::transaction(function () use ($attributes, $actor, $createdAt, $sourceContext): Incident {
            $event = Event::query()
                ->whereKey($this->requiredUuid($attributes, 'event_id', 'Incident event_id must be a valid UUID.'))
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw IncidentCreationException::invalid('Incident event does not exist.');
            }

            $now = CarbonImmutable::instance($createdAt ?? now());
            $startedAt = $this->startedAt($attributes['started_at'] ?? null, $now);
            $status = $this->status($attributes['status'] ?? Incident::STATUS_OPEN);
            $priorityLabel = $this->priorityLabel($attributes['priority_label'] ?? Incident::PRIORITY_ROUTINE);

            $incident = Incident::query()->create([
                'event_id' => $event->id,
                'incident_number' => $this->nextIncidentNumber($event),
                'status' => $status,
                'priority_label' => $priorityLabel,
                'started_at' => $startedAt,
                'title' => $this->title($attributes['title'] ?? null),
                'location_name' => $this->nullableString($attributes['location_name'] ?? null),
                'location_address' => $this->nullableString($attributes['location_address'] ?? null),
                'location_details' => $this->nullableString($attributes['location_details'] ?? null),
                'camp_id' => $this->nullableUuid($attributes['camp_id'] ?? null, 'Incident camp_id must be a valid UUID.'),
                'map_location_id' => $this->nullableUuid(
                    $attributes['map_location_id'] ?? null,
                    'Incident map_location_id must be a valid UUID.',
                ),
                'created_by_user_id' => $actor->id,
                'closed_at' => $status === Incident::STATUS_CLOSED ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncIncidentTypes($incident, $event, $attributes['incident_type_names'] ?? [], $now);
            $this->syncIncidentStaff($incident, $attributes['responder_staff_ids'] ?? [], $now);
            $incident->loadMissing(['incidentTypes', 'incidentStaff.staff']);

            $this->audit->recordForEntity(
                entity: $incident,
                action: 'incident.created',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                after: $this->auditSnapshot($incident),
                sourceContext: $sourceContext,
            );

            $this->timeline->recordIncidentOpened($incident, $actor, $now);
            $this->recordInitialFieldUpdate(
                $incident->refresh(),
                $actor,
                $attributes['initial_field_update_fields'] ?? [],
                $now,
            );

            return $incident->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requiredUuid(array $attributes, string $key, string $message): string
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw IncidentCreationException::invalid($message);
        }

        return $value;
    }

    private function nullableUuid(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw IncidentCreationException::invalid($message);
        }

        return $value;
    }

    private function title(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            throw IncidentCreationException::invalid('Incident title is invalid.');
        }

        $title = trim($value);

        if (mb_strlen($title) > 200) {
            throw IncidentCreationException::invalid('Incident title may not be greater than 200 characters.');
        }

        return $title;
    }

    private function status(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, Incident::statuses(), true)) {
            throw IncidentCreationException::invalid('Incident status is invalid.');
        }

        return $value;
    }

    private function priorityLabel(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, Incident::priorityLabels(), true)) {
            throw IncidentCreationException::invalid('Incident priority label is invalid.');
        }

        return $value;
    }

    private function startedAt(mixed $value, CarbonImmutable $default): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw IncidentCreationException::invalid('Incident started_at timestamp is invalid.');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw IncidentCreationException::invalid('Incident started_at timestamp is invalid.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $message, int $maxLength): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            throw IncidentCreationException::invalid($message);
        }

        $seen = [];
        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw IncidentCreationException::invalid($message);
            }

            $trimmed = trim($item);

            if ($trimmed === '') {
                continue;
            }

            if (mb_strlen($trimmed) > $maxLength) {
                throw IncidentCreationException::invalid($message);
            }

            $key = mb_strtolower($trimmed);

            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $items[] = $trimmed;
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function uuidList(mixed $value, string $message): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            throw IncidentCreationException::invalid($message);
        }

        $ids = [];

        foreach ($value as $item) {
            if (! is_string($item) || ! Str::isUuid($item)) {
                throw IncidentCreationException::invalid($message);
            }

            $ids[$item] = $item;
        }

        return array_values($ids);
    }

    /**
     * @param  list<string>|mixed  $typeNames
     */
    private function syncIncidentTypes(Incident $incident, Event $event, mixed $typeNames, CarbonImmutable $now): void
    {
        $names = $this->stringList($typeNames, 'Incident type names are invalid.', 100);
        $resolved = $this->incidentTypes->resolve($event, $names, $now);

        // An unrecognized name used to create the type. Incident types are the
        // organization's to configure (M18.14A), so now it is a refusal.
        if ($resolved['unknown'] !== []) {
            throw IncidentCreationException::invalid($this->incidentTypes->refusalFor($resolved['unknown']));
        }

        $incident->incidentTypes()->sync($resolved['pivot']);
    }

    /**
     * @param  list<string>|mixed  $staffIds
     */
    private function syncIncidentStaff(Incident $incident, mixed $staffIds, CarbonImmutable $now): void
    {
        $ids = $this->uuidList($staffIds, 'Incident responder staff IDs are invalid.');

        $existingStaffIds = Staff::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($existingStaffIds) !== count($ids)) {
            throw IncidentCreationException::invalid('Incident responder staff IDs are invalid.');
        }

        IncidentStaff::query()->where('incident_id', $incident->id)->delete();

        foreach ($ids as $staffId) {
            IncidentStaff::query()->create([
                'incident_id' => $incident->id,
                'staff_id' => $staffId,
                'relationship_label' => 'Responder',
                'created_at' => $now,
            ]);
        }
    }

    /**
     * @param  list<string>|mixed  $fields
     */
    private function recordInitialFieldUpdate(
        Incident $incident,
        User $actor,
        mixed $fields,
        CarbonImmutable $now,
    ): void {
        $fieldNames = $this->initialFieldUpdateFields($fields);

        if ($fieldNames === []) {
            return;
        }

        $before = $this->initialFieldDefaults();
        $after = $this->currentFieldSnapshot($incident);
        $deltaBefore = [];
        $deltaAfter = [];

        foreach ($fieldNames as $field) {
            if (($before[$field] ?? null) === ($after[$field] ?? null)) {
                continue;
            }

            $deltaBefore[$field] = $before[$field] ?? null;
            $deltaAfter[$field] = $after[$field] ?? null;
        }

        if ($deltaAfter === []) {
            return;
        }

        IncidentTimelineEntry::query()->create([
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_FIELD_UPDATED,
            'body' => $this->timelineBody($deltaAfter),
            'previous_value' => $deltaBefore,
            'new_value' => $deltaAfter,
            'created_at' => $now,
        ]);
    }

    /**
     * @return list<string>
     */
    private function initialFieldUpdateFields(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            throw IncidentCreationException::invalid('Initial incident field update fields are invalid.');
        }

        $allowed = array_flip(array_keys($this->initialFieldDefaults()));
        $fields = [];

        foreach ($value as $field) {
            if (! is_string($field) || ! array_key_exists($field, $allowed)) {
                throw IncidentCreationException::invalid('Initial incident field update fields are invalid.');
            }

            $fields[$field] = $field;
        }

        return array_values($fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialFieldDefaults(): array
    {
        return [
            'status' => Incident::STATUS_OPEN,
            'priority_label' => Incident::PRIORITY_ROUTINE,
            'started_at' => null,
            'title' => '',
            'location_name' => null,
            'location_address' => null,
            'location_details' => null,
            'camp_id' => null,
            'map_location_id' => null,
            'incident_type_names' => [],
            'responders' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentFieldSnapshot(Incident $incident): array
    {
        $incident->loadMissing(['incidentTypes', 'incidentStaff.staff']);

        return [
            'status' => $incident->status,
            'priority_label' => $incident->priority_label,
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
            'incident_type_names' => $incident->incidentTypes->pluck('name')->values()->all(),
            'responders' => $incident->incidentStaff
                ->map(fn (IncidentStaff $staff): array => [
                    'staff_id' => $staff->staff_id,
                    'display_name' => $staff->staff?->preferred_name
                        ?? $staff->staff?->handle
                        ?? $staff->staff?->legal_name
                        ?? 'Unknown responder',
                    'relationship_label' => $staff->relationship_label,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function timelineBody(array $after): string
    {
        return collect($after)
            ->map(fn (mixed $value, string $field): string => sprintf(
                'Changed %s: %s',
                str($this->fieldLabel($field))->lower()->toString(),
                $this->formatTimelineValue($field, $value),
            ))
            ->implode("\n");
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'started_at' => 'Started',
            'priority_label' => 'Priority',
            'incident_type_names' => 'Incident types',
            'responders' => 'Responders',
            'location_name' => 'Location name',
            'location_address' => 'Location address',
            'location_details' => 'Location details',
            'camp_id' => 'Camp',
            'map_location_id' => 'Map location',
            default => str($field)->replace('_', ' ')->title()->toString(),
        };
    }

    private function formatTimelineValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'not set';
        }

        if ($field === 'status') {
            return match ($value) {
                Incident::STATUS_OPEN => 'Open',
                Incident::STATUS_ON_SCENE => 'On Scene',
                Incident::STATUS_MONITORING => 'Monitoring',
                Incident::STATUS_ON_HOLD => 'On Hold',
                Incident::STATUS_CLOSED => 'Closed',
                default => (string) $value,
            };
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'none';
            }

            return collect($value)->map(function (mixed $item): string {
                if (is_array($item)) {
                    return (string) ($item['display_name'] ?? $item['name'] ?? $item['staff_id'] ?? 'Responder');
                }

                return (string) $item;
            })->implode(', ');
        }

        return (string) $value;
    }

    private function nextIncidentNumber(Event $event): string
    {
        if ($event->starts_at === null || $event->timezone === null) {
            throw IncidentCreationException::invalid(
                'Incident event must have a scheduled start and timezone before IMS number assignment.',
            );
        }

        $nextSequence = Incident::query()
            ->where('event_id', $event->id)
            ->pluck('incident_number')
            ->reduce(function (int $maximum, string $number): int {
                return preg_match('/^INC-\d{4}-(\d{6})$/', $number, $matches) === 1
                    ? max($maximum, (int) $matches[1])
                    : $maximum;
            }, 0) + 1;

        if ($nextSequence > 999999) {
            throw IncidentCreationException::invalid(
                'Incident IMS number sequence is exhausted for this event.',
            );
        }

        $eventYear = $event->starts_at->copy()->setTimezone($event->timezone)->year;

        return sprintf('INC-%04d-%06d', $eventYear, $nextSequence);
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Incident $incident): array
    {
        return [
            'event_id' => $incident->event_id,
            'incident_number' => $incident->incident_number,
            'status' => $incident->status,
            'priority_label' => $incident->priority_label,
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
            'incident_type_names' => $incident->incidentTypes->pluck('name')->values()->all(),
            'responders' => $incident->incidentStaff
                ->map(fn (IncidentStaff $staff): array => [
                    'staff_id' => $staff->staff_id,
                    'display_name' => $staff->staff?->preferred_name
                        ?? $staff->staff?->handle
                        ?? $staff->staff?->legal_name
                        ?? 'Unknown responder',
                    'relationship_label' => $staff->relationship_label,
                ])
                ->values()
                ->all(),
            'created_by_user_id' => $incident->created_by_user_id,
        ];
    }
}
