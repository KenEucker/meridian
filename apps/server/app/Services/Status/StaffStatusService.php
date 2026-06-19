<?php

namespace App\Services\Status;

use App\Models\DepartmentMembership;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

class StaffStatusService
{
    public function transitionOrganizationStatus(
        StaffOrganizationStatus $statusRecord,
        string $status,
        ?string $reason = null,
        ?User $changedBy = null,
        ?Carbon $changedAt = null,
    ): StaffOrganizationStatus {
        if (! in_array($status, StaffOrganizationStatus::statuses(), true)) {
            throw new InvalidArgumentException('Unsupported organization staff status.');
        }

        if ($status === StaffOrganizationStatus::STATUS_INACTIVE && $this->hasActiveDepartmentWork(
            $statusRecord->staff,
            $statusRecord->organization,
        )) {
            throw new RuntimeException('Active department work prevents organization-level inactive status.');
        }

        $statusRecord->forceFill([
            'status' => $status,
            'status_reason' => $reason,
            'status_changed_at' => $changedAt ?? now(),
            'status_changed_by_user_id' => $changedBy?->id,
        ])->save();

        return $statusRecord->refresh();
    }

    public function transitionDepartmentStatus(
        DepartmentMembership $departmentMembership,
        string $status,
        ?string $reason = null,
    ): DepartmentMembership {
        if (! in_array($status, DepartmentMembership::statuses(), true)) {
            throw new InvalidArgumentException('Unsupported department staff status.');
        }

        $departmentMembership->forceFill([
            'status' => $status,
            'status_reason' => $reason,
        ])->save();

        return $departmentMembership->refresh();
    }

    public function activateProspectiveOrganizationStatusForDepartmentAssignment(
        DepartmentMembership $departmentMembership,
        ?User $changedBy = null,
    ): ?StaffOrganizationStatus {
        $departmentMembership->loadMissing('department.organization');

        $statusRecord = StaffOrganizationStatus::query()
            ->where('staff_id', $departmentMembership->staff_id)
            ->where('organization_id', $departmentMembership->department->organization_id)
            ->first();

        if ($statusRecord === null || $statusRecord->status !== StaffOrganizationStatus::STATUS_PROSPECTIVE) {
            return null;
        }

        return $this->transitionOrganizationStatus(
            $statusRecord,
            StaffOrganizationStatus::STATUS_ACTIVE,
            'Activated after department assignment with no required trainings configured.',
            $changedBy,
        );
    }

    public function effectiveDepartmentStatus(DepartmentMembership $departmentMembership): string
    {
        $departmentMembership->loadMissing('department');

        $organizationStatus = StaffOrganizationStatus::query()
            ->where('staff_id', $departmentMembership->staff_id)
            ->where('organization_id', $departmentMembership->department->organization_id)
            ->first();

        if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
            return StaffOrganizationStatus::STATUS_DO_NOT_STAFF;
        }

        return $departmentMembership->status;
    }

    public function expireProspectiveOrganizationStatusIfDue(
        StaffOrganizationStatus $statusRecord,
        ?Carbon $asOf = null,
    ): StaffOrganizationStatus {
        if ($statusRecord->status !== StaffOrganizationStatus::STATUS_PROSPECTIVE) {
            return $statusRecord->refresh();
        }

        $statusRecord->loadMissing('organization');

        $thresholdYears = $statusRecord->organization->prospective_inactive_threshold_years;
        $effectiveSince = $statusRecord->status_changed_at ?? $statusRecord->created_at;
        $asOf ??= now();

        if ($thresholdYears === null || $effectiveSince === null) {
            return $statusRecord->refresh();
        }

        if ($effectiveSince->copy()->addYears($thresholdYears)->greaterThan($asOf)) {
            return $statusRecord->refresh();
        }

        return $this->transitionOrganizationStatus(
            $statusRecord,
            StaffOrganizationStatus::STATUS_INACTIVE,
            'Prospective status expired after configured threshold.',
            null,
            $asOf,
        );
    }

    public function organizationStatusBlocksSystemAccess(StaffOrganizationStatus $statusRecord): bool
    {
        return $statusRecord->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF;
    }

    private function hasActiveDepartmentWork(Staff $staff, Organization $organization): bool
    {
        return DepartmentMembership::query()
            ->where('staff_id', $staff->id)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->whereNull('archived_at')
            ->whereHas('department', function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id);
            })
            ->exists();
    }
}
