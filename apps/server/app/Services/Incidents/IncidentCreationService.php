<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentCreationException;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
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
 * Timelines, attachments, and sync projections are later M11 tasks.
 */
final class IncidentCreationService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{
     *     event_id: string,
     *     title: string,
     *     status?: string|null,
     *     started_at?: DateTimeInterface|string|null,
     *     location_name?: string|null,
     *     location_address?: string|null,
     *     location_details?: string|null,
     *     camp_id?: string|null,
     *     map_location_id?: string|null
     * }  $attributes
     */
    public function create(
        array $attributes,
        User $actor,
        ?DateTimeInterface $createdAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): Incident
    {
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

            $incident = Incident::query()->create([
                'event_id' => $event->id,
                'incident_number' => $this->nextIncidentNumber($event),
                'status' => $status,
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
        if (! is_string($value)) {
            throw IncidentCreationException::invalid('Incident title is required.');
        }

        $title = trim($value);

        if ($title === '') {
            throw IncidentCreationException::invalid('Incident title is required.');
        }

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
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
            'created_by_user_id' => $incident->created_by_user_id,
        ];
    }
}
