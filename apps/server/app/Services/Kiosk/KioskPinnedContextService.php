<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Auth\SharedWorkstationSessionService;
use App\Support\TypableCode;
use Illuminate\Support\Facades\DB;

/**
 * Which organization, event, and department a Kiosk workstation is pinned to
 * (M18.32; UI-019, UI-020, UI-021; technical spec 13.1).
 *
 * The pinned context is the persistence model for what a shared workstation is
 * working in. It has lived on `shared_workstations` since M16.8 and only a
 * seeder ever wrote it, which meant a Kiosk that finished an event had no way to
 * be moved to the next one.
 *
 * Four rules govern the write and each is technical spec 13.1 read literally.
 *
 *  1. **The organization is not the organizer's to change.** A workstation
 *     stands on somebody's site; whose site it is, is what God Mode registers
 *     along with the trusted device behind it. An organizer changes which event
 *     the machine is working, which is the change a season produces.
 *  2. **An event has to be the organization's own.** Pinning across
 *     organizations would put another organization's operational scope on a
 *     machine standing in this one's field.
 *  3. **A department has to work that event.** The optional department pin
 *     narrows the shell to one desk, and a desk for a department that does not
 *     work the event is a desk with nothing behind it — the same list ORG-006
 *     admits the Incident Command designation from (M18.31).
 *  4. **A live session does not survive the change.** The context frames the
 *     shell for whoever is signed in, so re-pinning under a live session would
 *     leave somebody looking at an event they did not sign in to. The session is
 *     ended as superseded, which is what it is.
 *
 * Every change is audited, which 13.1 requires in as many words.
 */
final class KioskPinnedContextService
{
    public const AUDIT_PINNED = 'shared_workstation.context_pinned';

    public function __construct(
        private readonly AuditService $audit,
        private readonly SharedWorkstationSessionService $sessions,
    ) {}

    /**
     * Pin a trusted workstation to an event, and optionally to one department.
     *
     * `$organization` is passed only by the God Mode path, which is the one
     * authority 13.1 gives the first pin to. Every other caller leaves it null
     * and the workstation keeps the organization it already has.
     *
     * @throws KioskPinnedContextException
     */
    public function pin(
        SharedWorkstation $workstation,
        Event $event,
        ?Department $department,
        User $actor,
        ?Organization $organization = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): SharedWorkstation {
        if (! $workstation->isTrusted()) {
            throw KioskPinnedContextException::workstationUntrusted();
        }

        $organizationId = (string) ($organization?->getKey() ?? $workstation->organization_id);

        if ($organizationId === '') {
            throw KioskPinnedContextException::organizationUnpinned();
        }

        if ((string) $event->organization_id !== $organizationId) {
            throw KioskPinnedContextException::eventOutOfScope();
        }

        if ($department !== null && ! $this->participates($event, $department)) {
            throw KioskPinnedContextException::departmentNotParticipating((string) $department->name);
        }

        $before = $this->snapshot($workstation);

        return DB::transaction(function () use (
            $workstation,
            $event,
            $department,
            $actor,
            $organizationId,
            $before,
            $sourceContext,
        ): SharedWorkstation {
            $this->endLiveSession($workstation);

            $workstation->forceFill([
                'organization_id' => $organizationId,
                'event_id' => (string) $event->getKey(),
                'department_id' => $department?->getKey(),
                // The typed fallback for a dead camera (M18.59; technical spec
                // 13.4; data/API 12.3): assigned when the workstation is pinned,
                // because that is when "unique per event" has an event to be
                // unique in. Kept stable across re-pins unless it collides in
                // the new event.
                'short_code' => $this->shortCodeFor($workstation, (string) $event->getKey()),
                'context_pinned_at' => now(),
                'context_pinned_by_user_id' => $actor->getKey(),
            ])->save();

            $after = $this->snapshot($workstation);

            $this->audit->recordForEntity(
                entity: $workstation,
                action: self::AUDIT_PINNED,
                actorUser: $actor,
                actorDevice: $workstation->device()->first(),
                organizationId: $organizationId,
                eventId: (string) $event->getKey(),
                departmentId: $department?->getKey() === null ? null : (string) $department->getKey(),
                before: $before,
                after: $after,
                sourceContext: $sourceContext,
            );

            return $workstation->refresh();
        });
    }

    /**
     * Whether a department is on the event's participating list (ORG-006,
     * PLACE-003; M18.31).
     *
     * Read from the same rows and the same `archived_at` filter every other
     * participation reader uses, so a department that was removed from the event
     * cannot keep a workstation pointed at it.
     */
    public function participates(Event $event, Department $department): bool
    {
        return EventDepartmentAssignment::query()
            ->where('event_id', $event->getKey())
            ->where('department_id', $department->getKey())
            ->whereNull('archived_at')
            ->exists();
    }

    /**
     * A `short_code` for the workstation, unique within the event it is being
     * pinned to (data/API 12.3).
     *
     * The same typable alphabet the login code and the mailed API code use,
     * because it is read off a locked screen and typed into a phone. It
     * identifies the workstation and is not a credential: knowing it buys
     * exactly what knowing the workstation's name buys.
     */
    private function shortCodeFor(SharedWorkstation $workstation, string $eventId): string
    {
        $current = (string) ($workstation->short_code ?? '');

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $current !== '' && $attempt === 0 ? $current : TypableCode::generate();

            $collides = SharedWorkstation::query()
                ->where('event_id', $eventId)
                ->where('short_code', $candidate)
                ->whereKeyNot($workstation->getKey())
                ->exists();

            if (! $collides) {
                return $candidate;
            }
        }

        // 25 straight collisions in a 30^8 space means the table, not the dice.
        throw new \RuntimeException('Could not assign a unique workstation short code for this event.');
    }

    /**
     * End whatever is signed in, because it was signed in to the old context.
     */
    private function endLiveSession(SharedWorkstation $workstation): void
    {
        $session = $this->sessions->activeSessionFor($workstation);

        if ($session instanceof SharedWorkstationSession) {
            $this->sessions->end($session, SharedWorkstationSession::ENDED_SUPERSEDED);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(SharedWorkstation $workstation): array
    {
        return [
            'shared_workstation_id' => (string) $workstation->getKey(),
            'organization_id' => $workstation->organization_id === null ? null : (string) $workstation->organization_id,
            'event_id' => $workstation->event_id === null ? null : (string) $workstation->event_id,
            'department_id' => $workstation->department_id === null ? null : (string) $workstation->department_id,
        ];
    }
}
