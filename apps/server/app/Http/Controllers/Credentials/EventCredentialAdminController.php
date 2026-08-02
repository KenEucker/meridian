<?php

declare(strict_types=1);

namespace App\Http\Controllers\Credentials;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\HoursWorked;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Services\Credential\CredentialRevocationAccess;
use App\Services\Credential\CredentialRevocationException;
use App\Services\Credential\CredentialRevocationService;
use App\Services\Credential\CredentialStatusReasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Event credential administration (M18.5; CRED-009 through CRED-014; UI
 * contract 12.6).
 *
 * `CredentialRevocationService` has enforced CRED-011 through CRED-013 since
 * M12.4 with nothing able to call it. This controller is the path to it: one
 * read that lists an event's credentials, and one command that revokes one.
 *
 * The read and the command carry the same authority deliberately. This list is
 * not a report — it is the set of people a revocation can be aimed at, and the
 * reason to open it is to aim one. Organizers and Incident Command leads are
 * who CRED-011 names, and `event.credentials.revoke` is where that now lives,
 * so a department lead who may export the same event's eligibility (REPORT-007)
 * is refused here. The two answer different questions about the same records.
 *
 * A row is not just a status. Revocation is an act with two halves — future
 * shifts go, completed shifts and recorded hours stay (CRED-012, CRED-013) —
 * and both halves are counted onto the row before anybody commits, using the
 * service's own rule for which shift is which. An organizer who has to guess
 * how much of somebody's schedule they are about to remove is one who will
 * either not act or act blind.
 */
final class EventCredentialAdminController extends Controller
{
    public function index(
        Request $request,
        Event $event,
        CredentialRevocationAccess $access,
        CredentialRevocationService $revocations,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canRevokeCredential($user, $event)) {
            return $this->refusal();
        }

        return response()->json([
            'event_id' => (string) $event->getKey(),
            'event_name' => $event->name,
            'credentials' => $this->rows($event, $revocations, Carbon::now()),
        ]);
    }

    /**
     * Revoke one credential (CRED-011 through CRED-013).
     *
     * Addressed by event and staff member rather than by credential id, because
     * a staff member with shifts and no credential row is revocable — that is
     * the `noCredentialHistory` refusal's other side — and there would be no id
     * to name. The reason is optional and free text: it reaches the audit entry
     * and nothing branches on it, so an organization can record "asked to leave
     * site" without the node having an opinion about the phrase.
     *
     * The answer is the staff member's whole row rebuilt, not an acknowledgment,
     * so the surface replaces what it was showing with what is now true —
     * including the completed shifts and recorded hours that survived.
     */
    public function revoke(
        Request $request,
        CredentialRevocationService $revocations,
    ): JsonResponse {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : '';

        try {
            $revocations->revoke(
                event: $event,
                staff: $staff,
                revoker: $user,
                reason: $reason === '' ? null : $reason,
            );
        } catch (CredentialRevocationException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $exception->status,
            );
        }

        $rows = $this->rows($event, $revocations, Carbon::now(), (string) $staff->getKey());

        return response()->json([
            'event_id' => (string) $event->getKey(),
            'credential' => $rows[0] ?? null,
        ]);
    }

    /**
     * Every staff member this event's credentials can be administered for.
     *
     * The union of two sets, and it has to be the union: a credential row is
     * the usual case, and a staff member carrying active assignments with no
     * credential row yet is the case `CredentialRevocationService` also accepts
     * (unscheduled work grants no eligibility, CRED-014, but it is still work
     * somebody is on the schedule for). Listing only the first set would hide
     * rows the command would act on; listing only the second would drop
     * everybody already revoked, who is exactly who an organizer checking their
     * own past decisions is looking for.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(
        Event $event,
        CredentialRevocationService $revocations,
        Carbon $moment,
        ?string $onlyStaffId = null,
    ): array {
        $credentials = EventCredential::query()
            ->where('event_id', $event->getKey())
            ->when($onlyStaffId !== null, fn ($query) => $query->where('staff_id', $onlyStaffId))
            ->get()
            ->keyBy(fn (EventCredential $credential): string => (string) $credential->staff_id);

        $assignments = ShiftAssignment::query()
            ->active()
            ->when($onlyStaffId !== null, fn ($query) => $query->where('staff_id', $onlyStaffId))
            ->whereHas('shift', fn ($query) => $query->where('event_id', $event->getKey()))
            ->with('shift.department')
            ->get()
            ->groupBy(fn (ShiftAssignment $assignment): string => (string) $assignment->staff_id);

        $staffIds = $credentials->keys()
            ->merge($assignments->keys())
            ->unique()
            ->values();

        if ($staffIds->isEmpty()) {
            return [];
        }

        $staff = Staff::query()
            ->whereIn('id', $staffIds->all())
            ->get()
            ->keyBy(fn (Staff $member): string => (string) $member->getKey());

        $recordedMinutes = HoursWorked::query()
            ->where('event_id', $event->getKey())
            ->whereIn('staff_id', $staffIds->all())
            ->get()
            ->groupBy(fn (HoursWorked $hours): string => (string) $hours->staff_id);

        return $staffIds
            ->map(fn (string $staffId): array => $this->row(
                staff: $staff->get($staffId),
                staffId: $staffId,
                credential: $credentials->get($staffId),
                assignments: $assignments->get($staffId) ?? collect(),
                hours: $recordedMinutes->get($staffId) ?? collect(),
                revocations: $revocations,
                moment: $moment,
            ))
            ->sortBy(fn (array $row): string => Str::lower((string) $row['display_name']).'|'.$row['staff_id'])
            ->values()
            ->all();
    }

    /**
     * One staff member's credential standing, and what revoking it would cost.
     *
     * Identity is the name, the handle, and the departments the shifts belong
     * to — enough to be sure who is being revoked, and no further. Email, phone,
     * and date of birth are absent for the same reason they are absent from the
     * eligibility export (REPORT-010): an Incident Command lead reaching this
     * surface holds no export capability at all, and revoking somebody is not a
     * reason to read their contact details.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @param  Collection<int, HoursWorked>  $hours
     * @return array<string, mixed>
     */
    private function row(
        ?Staff $staff,
        string $staffId,
        ?EventCredential $credential,
        Collection $assignments,
        Collection $hours,
        CredentialRevocationService $revocations,
        Carbon $moment,
    ): array {
        $completed = $assignments->filter(
            fn (ShiftAssignment $assignment): bool => $assignment->shift !== null
                && $revocations->isCompletedShift($assignment->shift, $moment),
        );

        $departments = $assignments
            ->map(fn (ShiftAssignment $assignment): ?string => $assignment->shift?->department_name_snapshot
                ?? $assignment->shift?->department?->name)
            ->filter(fn (?string $name): bool => $name !== null && $name !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $revoked = $credential?->isRevoked() ?? false;

        return [
            'staff_id' => $staffId,
            'legal_name' => $staff?->legal_name,
            'preferred_name' => $staff?->preferred_name,
            'display_name' => $staff?->preferred_name ?: ($staff?->legal_name ?? 'Unknown staff member'),
            'handle' => $staff?->handle,
            'departments' => $departments,
            // Null where the domain has recorded nothing yet, which is not the
            // same as Blocked and is not presented as though it were.
            'status' => $credential?->status,
            'status_reason' => $credential?->status_reason,
            'status_reason_label' => CredentialStatusReasons::label($credential?->status_reason),
            'revoked_at' => $credential?->revoked_at?->toIso8601String(),
            'credential_updated_at' => $credential?->updated_at?->toIso8601String(),
            // What revocation would remove, and what it would leave standing.
            'future_shift_count' => $assignments->count() - $completed->count(),
            'completed_shift_count' => $completed->count(),
            'recorded_minutes' => (int) $hours->sum(
                fn (HoursWorked $record): int => (int) ($record->minutes_worked ?? 0),
            ),
            'recorded_hours_records' => $hours->count(),
            'can_revoke' => ! $revoked,
            // Already revoked is the only state that refuses from the row
            // itself; every other refusal belongs to the command, which decides
            // it against the node's own reading rather than the screen's.
            'revoke_blocked_reason' => $revoked
                ? 'This credential is already revoked.'
                : null,
        ];
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers and Incident Command leads may administer event credentials.',
        ], 403);
    }
}
