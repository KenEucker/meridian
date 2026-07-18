<?php

namespace App\Services\FieldReports;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Field Report photo download authorization (technical spec 18.6).
 *
 * Only roles that grant `field_reports.download_photo` (ic_lead) may download
 * photo binaries. Preview follows Field Report view visibility.
 */
class FieldReportPhotoAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canDownloadEventFieldReportPhotos(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            $effectiveRoles = $this->roles->resolveForStaff($staff, $event);

            foreach ($effectiveRoles as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_FIELD_REPORTS_DOWNLOAD_PHOTO,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
