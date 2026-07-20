<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentAttachmentStrikeException;
use App\Models\Attachment;
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
 * Incident attachment strike path (INC-013; data/API section 10.17).
 */
final class IncidentAttachmentStrikeService
{
    public function __construct(private readonly AuditService $audit) {}

    public function strike(
        Incident $incident,
        Attachment $attachment,
        User $actor,
        string $reason,
        ?DateTimeInterface $strickenAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): Attachment {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($incident, $attachment, $actor, $reason, $strickenAt, $sourceContext): Attachment {
            $lockedIncident = $this->lockIncident($incident);
            $lockedAttachment = $this->lockAttachment($attachment);
            $this->assertIncidentAttachment($lockedIncident, $lockedAttachment);

            if ($lockedAttachment->stricken_at !== null) {
                throw IncidentAttachmentStrikeException::invalid('Incident attachment is already stricken.');
            }

            $before = $this->auditPayload($lockedAttachment);
            $now = CarbonImmutable::instance($strickenAt ?? now());

            Attachment::query()
                ->whereKey($lockedAttachment->id)
                ->update(['stricken_at' => $now]);

            IncidentTimelineEntry::query()->create([
                'incident_id' => $lockedIncident->id,
                'actor_user_id' => $actor->id,
                'entry_type' => IncidentTimelineEntry::TYPE_ATTACHMENT_STRICKEN,
                'body' => sprintf('Struck incident attachment %s.', $lockedAttachment->filename),
                'previous_value' => $before,
                'new_value' => [
                    'attachment_id' => $lockedAttachment->id,
                    'filename' => $lockedAttachment->filename,
                    'stricken_at' => $now->toIso8601String(),
                ],
                'reason' => $reason,
                'created_at' => $now,
            ]);

            $lockedIncident->forceFill(['updated_at' => $now])->save();
            $event = Event::query()->findOrFail($lockedIncident->event_id);
            $strickenAttachment = $lockedAttachment->refresh();

            $this->audit->recordForEntity(
                entity: $strickenAttachment,
                action: 'incident.attachment_stricken',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                before: $before,
                after: $this->auditPayload($strickenAttachment),
                reason: $reason,
                sourceContext: $sourceContext,
            );

            return $strickenAttachment;
        });
    }

    private function lockIncident(Incident $incident): Incident
    {
        return Incident::query()
            ->whereKey($incident->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockAttachment(Attachment $attachment): Attachment
    {
        return Attachment::query()
            ->whereKey($attachment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertIncidentAttachment(Incident $incident, Attachment $attachment): void
    {
        if (
            $attachment->attachable_type !== Attachment::MORPH_INCIDENT
            || (string) $attachment->attachable_id !== (string) $incident->id
        ) {
            throw IncidentAttachmentStrikeException::invalid('Attachment must belong to this incident.');
        }
    }

    private function reason(string $value): string
    {
        $reason = trim($value);

        if ($reason === '') {
            throw IncidentAttachmentStrikeException::invalid('Incident attachment strike reason is required.');
        }

        if (mb_strlen($reason) > 1000) {
            throw IncidentAttachmentStrikeException::invalid('Incident attachment strike reason may not be greater than 1000 characters.');
        }

        return $reason;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Attachment $attachment): array
    {
        return [
            'incident_id' => $attachment->attachable_id,
            'attachment_id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size,
            'storage_disk' => $attachment->storage_disk,
            'storage_path' => $attachment->storage_path,
            'checksum' => $attachment->checksum,
            'stricken_at' => optional($attachment->stricken_at)?->toIso8601String(),
            'deleted_at' => optional($attachment->deleted_at)?->toIso8601String(),
        ];
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
