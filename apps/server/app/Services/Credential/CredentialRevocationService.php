<?php

namespace App\Services\Credential;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manual credential revocation with future shift removal (CRED-011 through CRED-013).
 */
class CredentialRevocationService
{
    public const REASON_MANUAL_REVOCATION = 'manual_revocation';

    public function __construct(
        private readonly AuditService $audit,
        private readonly CredentialRevocationAccess $access,
    ) {}

    /**
     * @throws CredentialRevocationException when revocation is not permitted
     */
    public function revoke(
        Event $event,
        Staff $staff,
        User $revoker,
        ?string $reason = null,
        ?Carbon $moment = null,
    ): EventCredential {
        $moment ??= Carbon::now();

        if (! $this->access->canRevokeCredential($revoker, $event)) {
            throw CredentialRevocationException::unauthorized();
        }

        return DB::transaction(function () use ($event, $staff, $revoker, $reason, $moment): EventCredential {
            $credential = EventCredential::query()
                ->where('event_id', $event->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            $assignments = ShiftAssignment::query()
                ->active()
                ->where('staff_id', $staff->id)
                ->whereHas('shift', fn ($query) => $query->where('event_id', $event->id))
                ->with(['shift.event', 'shift.department'])
                ->lockForUpdate()
                ->get();

            if ($credential === null && $assignments->isEmpty()) {
                throw CredentialRevocationException::noCredentialHistory();
            }

            if ($credential?->isRevoked()) {
                return $credential;
            }

            $before = $credential !== null ? $this->credentialAuditSnapshot($credential) : null;

            $credential = $this->persistRevokedCredential(
                credential: $credential ?? new EventCredential([
                    'event_id' => $event->id,
                    'staff_id' => $staff->id,
                ]),
                revoker: $revoker,
                reason: $reason,
                moment: $moment,
            );

            $this->audit->recordForEntity(
                entity: $credential,
                action: 'event_credential.revoked',
                actorUser: $revoker,
                organizationId: $event->organization_id,
                eventId: $event->id,
                before: $before,
                after: $this->credentialAuditSnapshot($credential),
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_API,
            );

            $this->removeFutureShiftAssignments($assignments, $revoker, $moment);

            return $credential->refresh();
        });
    }

    private function persistRevokedCredential(
        EventCredential $credential,
        User $revoker,
        ?string $reason,
        Carbon $moment,
    ): EventCredential {
        $credential->forceFill([
            'status' => EventCredential::STATUS_REVOKED,
            'status_reason' => self::REASON_MANUAL_REVOCATION,
            'changed_by_user_id' => $revoker->id,
            'revoked_at' => $moment,
        ])->save();

        return $credential;
    }

    /**
     * @param  Collection<int, ShiftAssignment>  $assignments
     */
    private function removeFutureShiftAssignments(
        Collection $assignments,
        User $revoker,
        Carbon $moment,
    ): void {
        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;

            if ($shift === null || $this->isCompletedShift($shift, $moment)) {
                continue;
            }

            $before = $this->assignmentAuditSnapshot($assignment);

            $assignment->forceFill(['removed_at' => $moment])->save();

            $this->audit->recordForEntity(
                entity: $assignment,
                action: 'shift_assignment.removed_by_credential_revocation',
                actorUser: $revoker,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                before: $before,
                after: $this->assignmentAuditSnapshot($assignment->refresh()),
                sourceContext: AuditEvent::SOURCE_API,
            );
        }
    }

    /**
     * Whether a shift is one revocation leaves alone (CRED-012, CRED-013).
     *
     * Public because the credential administration surface counts what a
     * revocation would remove and what it would preserve before an organizer
     * commits to it (M18.5), and a preview computed from a second copy of this
     * rule is a preview that can disagree with the act. A shift with no
     * recorded end has not completed and is removed.
     */
    public function isCompletedShift(Shift $shift, Carbon $moment): bool
    {
        return $shift->ends_at !== null && $shift->ends_at->lte($moment);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialAuditSnapshot(EventCredential $credential): array
    {
        return [
            'event_id' => $credential->event_id,
            'staff_id' => $credential->staff_id,
            'status' => $credential->status,
            'status_reason' => $credential->status_reason,
            'changed_by_user_id' => $credential->changed_by_user_id,
            'revoked_at' => $credential->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentAuditSnapshot(ShiftAssignment $assignment): array
    {
        return [
            'shift_id' => $assignment->shift_id,
            'staff_id' => $assignment->staff_id,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assignment_status' => $assignment->assignment_status,
            'removed_at' => $assignment->removed_at?->toIso8601String(),
        ];
    }
}
