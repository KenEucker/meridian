<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentUpdateException;
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
 * Autosaved online incident field edits (M11.7).
 *
 * Incident body/history remains append-only. This service only updates current
 * incident fields and writes audit/timeline history for changed values.
 */
final class IncidentUpdateService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly IncidentTypeResolver $incidentTypes,
    ) {}

    /**
     * @param  array{
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
     *     responder_staff_ids?: list<string>|null
     * }  $attributes
     */
    public function update(
        Incident $incident,
        User $actor,
        array $attributes,
        ?DateTimeInterface $updatedAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): Incident {
        return DB::transaction(function () use ($incident, $actor, $attributes, $updatedAt, $sourceContext): Incident {
            /** @var Incident $lockedIncident */
            $lockedIncident = Incident::query()
                ->with(['incidentTypes', 'incidentStaff.staff'])
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            $event = Event::query()->findOrFail($lockedIncident->event_id);
            $now = CarbonImmutable::instance($updatedAt ?? now());
            $changes = $this->changes($lockedIncident, $attributes, $now);

            $before = $this->snapshot($lockedIncident);

            if ($changes !== []) {
                $lockedIncident->forceFill($changes)->save();
            }

            if (array_key_exists('incident_type_names', $attributes)) {
                $this->syncIncidentTypes($lockedIncident, $event, $attributes['incident_type_names'], $now);
            }

            if (array_key_exists('responder_staff_ids', $attributes)) {
                $this->syncIncidentStaff($lockedIncident, $attributes['responder_staff_ids'], $now);
            }

            $after = $this->snapshot($lockedIncident->refresh());
            $deltaBefore = [];
            $deltaAfter = [];

            foreach ($after as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $deltaBefore[$field] = $before[$field] ?? null;
                    $deltaAfter[$field] = $value;
                }
            }

            unset($deltaBefore['updated_at'], $deltaAfter['updated_at']);

            if ($deltaAfter === []) {
                return $lockedIncident->refresh();
            }

            $lockedIncident->forceFill(['updated_at' => $now])->save();
            $after = $this->snapshot($lockedIncident->refresh());
            $deltaAfter = array_intersect_key($after, $deltaBefore);

            IncidentTimelineEntry::query()->create([
                'incident_id' => $lockedIncident->id,
                'actor_user_id' => $actor->id,
                'entry_type' => IncidentTimelineEntry::TYPE_FIELD_UPDATED,
                'body' => $this->timelineBody($deltaAfter),
                'previous_value' => $deltaBefore,
                'new_value' => $deltaAfter,
                'created_at' => $now,
            ]);

            $this->audit->recordForEntity(
                entity: $lockedIncident,
                action: 'incident.updated',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                before: $deltaBefore,
                after: $deltaAfter,
                sourceContext: $sourceContext,
            );

            return $lockedIncident->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function changes(Incident $incident, array $attributes, CarbonImmutable $now): array
    {
        $changes = [];

        if (array_key_exists('title', $attributes)) {
            $changes['title'] = $this->title($attributes['title']);
        }

        if (array_key_exists('status', $attributes)) {
            $status = $this->status($attributes['status']);
            $changes['status'] = $status;

            if ($status === Incident::STATUS_CLOSED && $incident->status !== Incident::STATUS_CLOSED) {
                $changes['closed_at'] = $now;
            } elseif ($status !== Incident::STATUS_CLOSED && $incident->status === Incident::STATUS_CLOSED) {
                $changes['closed_at'] = null;
            }
        }

        if (array_key_exists('priority_label', $attributes)) {
            $changes['priority_label'] = $this->priorityLabel($attributes['priority_label']);
        }

        if (array_key_exists('started_at', $attributes)) {
            $changes['started_at'] = $this->startedAt($attributes['started_at']);
        }

        foreach (['location_name', 'location_address', 'location_details'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $changes[$field] = $this->nullableString($attributes[$field]);
            }
        }

        foreach (['camp_id', 'map_location_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $changes[$field] = $attributes[$field] === '' ? null : $attributes[$field];
            }
        }

        $changes = array_filter(
            $changes,
            fn (mixed $value, string $field): bool => $this->changed($incident, $field, $value),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes !== []) {
            $changes['updated_at'] = $now;
        }

        return $changes;
    }

    private function title(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            throw IncidentUpdateException::invalid('Incident title is invalid.');
        }

        $title = trim($value);

        if (mb_strlen($title) > 200) {
            throw IncidentUpdateException::invalid('Incident title may not be greater than 200 characters.');
        }

        return $title;
    }

    private function status(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, Incident::statuses(), true)) {
            throw IncidentUpdateException::invalid('Incident status is invalid.');
        }

        return $value;
    }

    private function priorityLabel(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, Incident::priorityLabels(), true)) {
            throw IncidentUpdateException::invalid('Incident priority label is invalid.');
        }

        return $value;
    }

    private function startedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw IncidentUpdateException::invalid('Incident started_at timestamp is invalid.');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw IncidentUpdateException::invalid('Incident started_at timestamp is invalid.');
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
            throw IncidentUpdateException::invalid($message);
        }

        $seen = [];
        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw IncidentUpdateException::invalid($message);
            }

            $trimmed = trim($item);

            if ($trimmed === '') {
                continue;
            }

            if (mb_strlen($trimmed) > $maxLength) {
                throw IncidentUpdateException::invalid($message);
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
            throw IncidentUpdateException::invalid($message);
        }

        $ids = [];

        foreach ($value as $item) {
            if (! is_string($item) || ! Str::isUuid($item)) {
                throw IncidentUpdateException::invalid($message);
            }

            $ids[$item] = $item;
        }

        return array_values($ids);
    }

    private function syncIncidentTypes(Incident $incident, Event $event, mixed $typeNames, CarbonImmutable $now): void
    {
        $names = $this->stringList($typeNames, 'Incident type names are invalid.', 100);
        $resolved = $this->incidentTypes->resolve($event, $names, $now);

        // An unrecognized name used to create the type. Incident types are the
        // organization's to configure (M18.14A), so now it is a refusal.
        if ($resolved['unknown'] !== []) {
            throw IncidentUpdateException::invalid($this->incidentTypes->refusalFor($resolved['unknown']));
        }

        $incident->incidentTypes()->sync($resolved['pivot']);
    }

    private function syncIncidentStaff(Incident $incident, mixed $staffIds, CarbonImmutable $now): void
    {
        $ids = $this->uuidList($staffIds, 'Incident responder staff IDs are invalid.');

        $existingStaffIds = Staff::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($existingStaffIds) !== count($ids)) {
            throw IncidentUpdateException::invalid('Incident responder staff IDs are invalid.');
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

    private function changed(Incident $incident, string $field, mixed $value): bool
    {
        $current = $incident->{$field};

        if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
            return optional($current)?->toIso8601String() !== optional($value)?->toIso8601String();
        }

        return $current !== $value;
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
            'closed_at' => 'Closed at',
            'updated_at' => 'Updated at',
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

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Incident $incident): array
    {
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
            'incident_type_names' => $incident->incidentTypes()
                ->pluck('name')
                ->values()
                ->all(),
            'responders' => $incident->incidentStaff()
                ->with('staff')
                ->get()
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
            'closed_at' => optional($incident->closed_at)?->toIso8601String(),
            'updated_at' => optional($incident->updated_at)?->toIso8601String(),
        ];
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
