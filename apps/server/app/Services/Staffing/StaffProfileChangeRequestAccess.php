<?php

namespace App\Services\Staffing;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\StaffProfileChangeRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Support\Collection;

/**
 * Who reviews a staff profile change request (M18.20A; VOL-019, VOL-024).
 *
 * Two questions, and they are not the same one. *Whose* request may somebody
 * decide comes from the capability: `staff.profile-change-requests.review`,
 * which organizers, Lead Organizers, and Staff Coordinators hold and nobody
 * else does. *Which* organization reviews a given staff member comes from the
 * staff member's own standing — the organizations they hold a status with — so
 * a request is decided by the organization the person actually works for
 * rather than by whichever organizer happened to open the list.
 *
 * A staff member is never a reviewer of their own request, even where they
 * hold the capability. An organizer who wants a new handle asks the same way
 * everybody else does; approving one's own request would make the review a
 * formality for exactly the people whose handles are most visible.
 */
final class StaffProfileChangeRequestAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * The organizations whose profile change requests this user may decide.
     *
     * @return Collection<int, string>
     */
    public function reviewableOrganizationIds(User $user): Collection
    {
        $organizationIds = collect();

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team === null || $team->department === null) {
                    continue;
                }

                $organizationIds->push((string) $team->department->organization_id);
            }
        }

        return $organizationIds->unique()->values();
    }

    public function canReviewForOrganization(User $user, string $organizationId): bool
    {
        return $this->reviewableOrganizationIds($user)->contains($organizationId);
    }

    /**
     * Whether this user may decide this request.
     *
     * The submitter is excluded before the capability is consulted, so holding
     * review authority never becomes authority over one's own request.
     */
    public function canReview(User $user, StaffProfileChangeRequest $request): bool
    {
        if ($this->isOwnProfile($user, (string) $request->staff_id)) {
            return false;
        }

        return $this->canReviewForOrganization($user, (string) $request->organization_id);
    }

    /** Whether this staff record is one the user's login speaks for. */
    public function isOwnProfile(User $user, string $staffId): bool
    {
        return $user->staffProfiles()->whereKey($staffId)->exists();
    }

    /**
     * The organization that reviews this staff member's requests.
     *
     * The one they hold a status with. With several, the earliest — a request
     * is a fact about the person rather than about one organization, and the
     * alternative is asking a staff member to choose which of their
     * organizations should decide their handle, which is not a question they
     * can answer meaningfully.
     */
    public function reviewingOrganizationId(Staff $staff): ?string
    {
        $status = StaffOrganizationStatus::query()
            ->where('staff_id', $staff->id)
            ->orderBy('created_at')
            ->first();

        return $status === null ? null : (string) $status->organization_id;
    }

    /**
     * Whether the staff member is `active` in at least one organization, which
     * is what technical spec 18A.1 requires before a picture may be submitted.
     */
    public function isActiveSomewhere(Staff $staff): bool
    {
        return StaffOrganizationStatus::query()
            ->where('staff_id', $staff->id)
            ->where('status', StaffOrganizationStatus::STATUS_ACTIVE)
            ->exists();
    }
}
