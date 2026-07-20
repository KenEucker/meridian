<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentUpdateException;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Autosaved online incident field edits (M11.7).
 *
 * Incident body/history remains append-only. This service only updates current
 * incident fields and writes audit/timeline history for changed values.
 */
final class IncidentUpdateService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     status?: string|null,
     *     started_at?: DateTimeInterface|string|null,
     *     location_name?: string|null,
     *     location_address?: string|null,
     *     location_details?: string|null,
     *     camp_id?: string|null,
     *     map_location_id?: string|null
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
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            $event = Event::query()->findOrFail($lockedIncident->event_id);
            $now = CarbonImmutable::instance($updatedAt ?? now());
            $changes = $this->changes($lockedIncident, $attributes, $now);

            if ($changes === []) {
                return $lockedIncident->refresh();
            }

            $before = $this->snapshot($lockedIncident);

            $lockedIncident->forceFill($changes)->save();

            $after = $this->snapshot($lockedIncident->refresh());
            $fieldChanges = $changes;
            unset($fieldChanges['updated_at']);
            $deltaBefore = array_intersect_key($before, $fieldChanges);
            $deltaAfter = array_intersect_key($after, $fieldChanges);

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
        if (! is_string($value)) {
            throw IncidentUpdateException::invalid('Incident title is required.');
        }

        $title = trim($value);

        if ($title === '') {
            throw IncidentUpdateException::invalid('Incident title is required.');
        }

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

        return (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Incident $incident): array
    {
        return [
            'status' => $incident->status,
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
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
