<?php

namespace App\Services\Staffing;

use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle both profile change request kinds share (M18.20A; VOL-017
 * through VOL-026; data/API 10.4).
 *
 * A handle change and a picture submission differ in what they carry and in
 * nothing else: both are one outstanding request per kind per staff member,
 * both are withdrawable by their submitter and by nobody else, both are
 * decided by the organization the staff member holds a status with, and all
 * three transitions are audited. That shared shape lives here; what is
 * peculiar to a picture — the image bytes, the processing, the storage — lives
 * in {@see StaffProfilePictureService}, which drives this one.
 *
 * The VOL-017 allowance is counted rather than stored. Every handle change is
 * written here including the two that apply without review, so the number
 * spent is the number of `approved` handle rows that were real changes, and a
 * rejected or withdrawn request restores nothing because it consumed nothing
 * (VOL-018). There is no counter column to drift from the history.
 */
final class StaffProfileChangeRequestService
{
    /** Applied handle changes a staff record gets before review begins (VOL-017). */
    public const SELF_SERVICE_HANDLE_CHANGES = 2;

    public function __construct(
        private readonly AuditService $audit,
        private readonly StaffProfileChangeRequestAccess $access,
    ) {}

    /**
     * Open a request, or apply the change outright where the rules allow it.
     *
     * `$apply` runs inside the transaction when the change takes effect
     * immediately — a first handle, or one still inside the allowance — so the
     * request row and the change it describes are written together or not at
     * all.
     *
     * @param  array<string, mixed>  $attributes
     * @param  Closure(StaffProfileChangeRequest): void|null  $apply
     */
    public function open(
        Staff $staff,
        User $actor,
        string $kind,
        array $attributes,
        bool $selfService,
        ?Closure $apply = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        $organizationId = $this->access->reviewingOrganizationId($staff);

        if ($organizationId === null) {
            throw new StaffProfileSelfException(
                'This staff record holds no organization status, so there is nobody to review a profile change for it.',
            );
        }

        return DB::transaction(function () use (
            $staff,
            $actor,
            $kind,
            $attributes,
            $selfService,
            $apply,
            $organizationId,
            $sourceContext,
        ): StaffProfileChangeRequest {
            $this->assertNoOutstandingRequest($staff, $kind);

            $request = StaffProfileChangeRequest::query()->create([
                ...$attributes,
                'staff_id' => $staff->id,
                'organization_id' => $organizationId,
                'requested_by_user_id' => $actor->id,
                'kind' => $kind,
                'status' => $selfService
                    ? StaffProfileChangeRequest::STATUS_APPROVED
                    : StaffProfileChangeRequest::STATUS_PENDING,
                'self_service' => $selfService,
                // A self-service change is decided by nobody and applied now,
                // which is what data/API 10.4 records: `decided_at` set, no
                // deciding user.
                'decided_at' => $selfService ? now() : null,
            ]);

            if ($selfService && $apply !== null) {
                $apply($request);
            }

            $this->audit->recordForEntity(
                entity: $request,
                action: $selfService
                    ? 'staff.profile_change_request.applied'
                    : 'staff.profile_change_request.created',
                actorUser: $actor,
                organizationId: $organizationId,
                after: $this->snapshot($request),
                sourceContext: $sourceContext,
            );

            return $request->refresh();
        });
    }

    /**
     * Decide a pending request.
     *
     * `$apply` runs inside the transaction on approval and `$discard` on
     * rejection, so a promoted picture and a discarded one are each committed
     * with the decision that caused them (VOL-022).
     *
     * @param  Closure(StaffProfileChangeRequest): void|null  $apply
     * @param  Closure(StaffProfileChangeRequest): void|null  $discard
     */
    public function decide(
        StaffProfileChangeRequest $request,
        User $reviewer,
        bool $approve,
        ?string $reason = null,
        ?Closure $apply = null,
        ?Closure $discard = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        if (! $this->access->canReview($reviewer, $request)) {
            throw new StaffProfileSelfException(
                'You do not review profile change requests for this staff member.',
            );
        }

        return DB::transaction(function () use (
            $request,
            $reviewer,
            $approve,
            $reason,
            $apply,
            $discard,
            $sourceContext,
        ): StaffProfileChangeRequest {
            $locked = StaffProfileChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                throw new StaffProfileSelfException(
                    'This request has already been decided.',
                );
            }

            $before = $this->snapshot($locked);

            $locked->forceFill([
                'status' => $approve
                    ? StaffProfileChangeRequest::STATUS_APPROVED
                    : StaffProfileChangeRequest::STATUS_REJECTED,
                'decided_by_user_id' => $reviewer->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            if ($approve && $apply !== null) {
                $apply($locked);
            }

            if (! $approve && $discard !== null) {
                $discard($locked);
            }

            $locked->refresh();

            $this->audit->recordForEntity(
                entity: $locked,
                action: $approve
                    ? 'staff.profile_change_request.approved'
                    : 'staff.profile_change_request.rejected',
                actorUser: $reviewer,
                organizationId: (string) $locked->organization_id,
                before: $before,
                after: $this->snapshot($locked),
                reason: $reason,
                sourceContext: $sourceContext,
            );

            return $locked;
        });
    }

    /**
     * Withdraw one's own pending request (VOL-024).
     *
     * `$discard` releases anything the request was holding — for a picture,
     * the submitted image, which VOL-022 says is discarded rather than kept.
     *
     * @param  Closure(StaffProfileChangeRequest): void|null  $discard
     */
    public function withdraw(
        StaffProfileChangeRequest $request,
        User $actor,
        ?Closure $discard = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        if (! $this->access->isOwnProfile($actor, (string) $request->staff_id)) {
            throw new StaffProfileSelfException(
                'You can only withdraw your own profile change request.',
            );
        }

        return DB::transaction(function () use ($request, $actor, $discard, $sourceContext): StaffProfileChangeRequest {
            $locked = StaffProfileChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                throw new StaffProfileSelfException(
                    'This request has already been decided and cannot be withdrawn.',
                );
            }

            $before = $this->snapshot($locked);

            $locked->forceFill([
                'status' => StaffProfileChangeRequest::STATUS_WITHDRAWN,
                'decided_at' => now(),
            ])->save();

            if ($discard !== null) {
                $discard($locked);
            }

            $locked->refresh();

            $this->audit->recordForEntity(
                entity: $locked,
                action: 'staff.profile_change_request.withdrawn',
                actorUser: $actor,
                organizationId: (string) $locked->organization_id,
                before: $before,
                after: $this->snapshot($locked),
                sourceContext: $sourceContext,
            );

            return $locked;
        });
    }

    /**
     * How many self-service handle changes this staff record has left
     * (VOL-017, VOL-018).
     *
     * Counted from applied changes: `approved` handle rows that actually moved
     * a handle. Setting a first handle is not a change and is stored with a
     * null `previous_handle`, so it is not counted here either — which is the
     * rule rather than an implementation detail worth restating at each call
     * site.
     */
    public function remainingSelfServiceHandleChanges(Staff $staff): int
    {
        $applied = StaffProfileChangeRequest::query()
            ->where('staff_id', $staff->id)
            ->where('kind', StaffProfileChangeRequest::KIND_HANDLE)
            ->where('status', StaffProfileChangeRequest::STATUS_APPROVED)
            ->whereNotNull('previous_handle')
            ->count();

        return max(0, self::SELF_SERVICE_HANDLE_CHANGES - $applied);
    }

    /** The staff member's outstanding request of this kind, if any (VOL-024). */
    public function pendingRequest(Staff $staff, string $kind): ?StaffProfileChangeRequest
    {
        return StaffProfileChangeRequest::query()
            ->pending()
            ->where('staff_id', $staff->id)
            ->where('kind', $kind)
            ->first();
    }

    /**
     * Any other active staff member in the organization already using this
     * handle (VOL-020).
     *
     * Resolved at read time rather than stored, so a collision that appears or
     * clears between submission and review is the one the reviewer is shown.
     * It names rather than blocks: the reviewer decides.
     *
     * @return list<string>
     */
    public function handleCollisions(StaffProfileChangeRequest $request): array
    {
        if ($request->kind !== StaffProfileChangeRequest::KIND_HANDLE
            || $request->requested_handle === null) {
            return [];
        }

        return Staff::query()
            ->active()
            ->whereKeyNot($request->staff_id)
            ->where('handle', $request->requested_handle)
            ->whereHas('organizationStatuses', fn ($status) => $status
                ->where('organization_id', $request->organization_id)
                ->where('status', StaffOrganizationStatus::STATUS_ACTIVE))
            ->get()
            ->map(fn (Staff $staff): string => $staff->preferred_name ?: $staff->legal_name)
            ->values()
            ->all();
    }

    private function assertNoOutstandingRequest(Staff $staff, string $kind): void
    {
        $outstanding = StaffProfileChangeRequest::query()
            ->pending()
            ->where('staff_id', $staff->id)
            ->where('kind', $kind)
            ->lockForUpdate()
            ->exists();

        if (! $outstanding) {
            return;
        }

        throw new StaffProfileSelfException($kind === StaffProfileChangeRequest::KIND_HANDLE
            ? 'You already have a handle change waiting for review. Withdraw it before requesting another.'
            : 'You already have a profile picture waiting for review. Withdraw it before submitting another.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(StaffProfileChangeRequest $request): array
    {
        return [
            'kind' => $request->kind,
            'status' => $request->status,
            'previous_handle' => $request->previous_handle,
            'requested_handle' => $request->requested_handle,
            'pending_picture_path' => $request->pending_picture_path,
            'self_service' => $request->self_service,
            'decision_reason' => $request->decision_reason,
        ];
    }
}
