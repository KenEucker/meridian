<?php

namespace App\Services\Staffing;

use App\Domain\Staffing\ProfileChangePolicy;
use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use App\Services\Audit\AuditService;

/**
 * Handle changes, the self-service allowance, and the organization's policy
 * (M18.20B; VOL-017, VOL-018, VOL-020, VOL-026, VOL-027, VOL-028).
 *
 * Every handle change is a request row, including the ones that apply without
 * review. That is what makes the allowance countable: the number spent is read
 * from the history rather than from a counter that could disagree with it.
 *
 * What happens to a given change is the organization's decision (VOL-027).
 * Under the default policy every change is reviewed. Under `auto_approved` a
 * change applies immediately until the allowance runs out and is reviewed
 * after that, which is the behaviour VOL-017 describes and which an
 * organization now opts into rather than receiving. Under `staff_sets_first`
 * only the first handle is free. Under `organizer_sets_first` a staff member
 * with no handle cannot propose one at all — they are waiting on an organizer,
 * not being refused, and the message says so.
 *
 * Setting a first handle is never a change (VOL-017). A staff record created
 * with no handle has not used a handle yet, so choosing one is completing the
 * profile rather than replacing something other people already know somebody
 * by — which is the thing the allowance exists to ration.
 */
final class StaffHandleChangeService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly StaffProfileChangeRequestService $requests,
        private readonly StaffProfileChangeRequestAccess $access,
    ) {}

    /**
     * Request a handle, applying it now where the rules allow.
     *
     * The returned request says which happened: `approved` with `self_service`
     * true applied, `pending` is waiting for a reviewer.
     */
    public function requestHandle(
        Staff $staff,
        User $actor,
        string $requestedHandle,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        $handle = trim($requestedHandle);

        if ($handle === '') {
            throw new StaffProfileSelfException('A handle cannot be blank. Ask an organizer to clear one.');
        }

        $previous = $staff->handle;

        if ($previous === $handle) {
            throw new StaffProfileSelfException('That is already your handle.');
        }

        $isFirstHandle = $previous === null || $previous === '';
        $policy = $this->access->reviewingOrganization($staff)?->handleChangePolicy()
            ?? ProfileChangePolicy::default();

        if ($isFirstHandle && ! $policy->allowsStaffFirstValue()) {
            throw new StaffProfileSelfException(
                'Your organization issues first handles. Ask an organizer to set yours, and you can request changes to it after that.',
            );
        }

        /*
         * A first handle applies now where the policy says so; a change to an
         * existing handle applies now only while the allowance holds. Both
         * fall through to a reviewed request otherwise, which is what every
         * mode except `auto_approved` does with a change.
         */
        $selfService = $isFirstHandle
            ? $policy->appliesFirstValueWithoutReview()
            : $this->requests->remainingSelfServiceHandleChanges($staff) > 0;

        return $this->requests->open(
            staff: $staff,
            actor: $actor,
            kind: StaffProfileChangeRequest::KIND_HANDLE,
            attributes: [
                'previous_handle' => $isFirstHandle ? null : $previous,
                'requested_handle' => $handle,
            ],
            selfService: $selfService,
            apply: fn (StaffProfileChangeRequest $request) => $this->applyHandle($request, $actor, $sourceContext),
            sourceContext: $sourceContext,
        );
    }

    /**
     * Approve a pending handle request, which applies the handle (VOL-019).
     *
     * A collision is named to the reviewer by the read rather than refused
     * here (VOL-020): two people may legitimately be told apart by their
     * departments, and it is the reviewer's decision to make.
     */
    public function approve(
        StaffProfileChangeRequest $request,
        User $reviewer,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        return $this->requests->decide(
            request: $request,
            reviewer: $reviewer,
            approve: true,
            reason: $reason,
            apply: fn (StaffProfileChangeRequest $decided) => $this->applyHandle($decided, $reviewer, $sourceContext),
            sourceContext: $sourceContext,
        );
    }

    /** Reject a pending handle request, leaving the current handle alone. */
    public function reject(
        StaffProfileChangeRequest $request,
        User $reviewer,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        return $this->requests->decide(
            request: $request,
            reviewer: $reviewer,
            approve: false,
            reason: $reason,
            sourceContext: $sourceContext,
        );
    }

    /**
     * Write the requested handle onto the staff record, and audit the staff
     * record's own change beside the request's (VOL-026).
     *
     * Two audit entries rather than one, because they answer different
     * questions: the request's entry says a decision happened, and this one
     * says the person's handle is now different, which is what somebody
     * auditing the staff record came to find.
     */
    private function applyHandle(
        StaffProfileChangeRequest $request,
        User $actor,
        string $sourceContext,
    ): void {
        $staff = Staff::query()->whereKey($request->staff_id)->lockForUpdate()->firstOrFail();
        $before = $staff->handle;

        $staff->forceFill(['handle' => $request->requested_handle])->save();

        $this->audit->recordForEntity(
            entity: $staff,
            action: 'staff.profile.handle_changed',
            actorUser: $actor,
            organizationId: (string) $request->organization_id,
            before: ['handle' => $before],
            after: ['handle' => $staff->handle],
            sourceContext: $sourceContext,
        );
    }
}
