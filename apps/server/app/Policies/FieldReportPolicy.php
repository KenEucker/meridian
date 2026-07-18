<?php

namespace App\Policies;

use App\Models\FieldReport;
use App\Models\User;
use App\Services\FieldReports\FieldReportVisibilityAccess;

/**
 * Field Report authorization (FR-004 through FR-009; technical spec 17.4, 17.6).
 *
 * Authors may view their own submitted reports. IC roles with
 * `field_reports.view_event` may view all Field Reports for the granted event.
 * Non-authors without that permission are denied, including department leads,
 * organizers, and shift leads. Updates and deletes are always denied (FR-007).
 * Only the original author may append (FR-009); elevated IC roles cannot append
 * to other users' Field Reports.
 */
class FieldReportPolicy
{
    public function __construct(
        private readonly FieldReportVisibilityAccess $visibility,
    ) {}

    public function view(User $user, FieldReport $fieldReport): bool
    {
        if ($this->isAuthor($user, $fieldReport)) {
            return true;
        }

        $event = $fieldReport->event;

        if ($event === null) {
            return false;
        }

        return $this->visibility->canViewEventFieldReports($user, $event);
    }

    public function append(User $user, FieldReport $fieldReport): bool
    {
        return $this->isAuthor($user, $fieldReport);
    }

    public function update(User $user, FieldReport $fieldReport): bool
    {
        return false;
    }

    public function delete(User $user, FieldReport $fieldReport): bool
    {
        return false;
    }

    private function isAuthor(User $user, FieldReport $fieldReport): bool
    {
        return (string) $fieldReport->submitted_by_user_id === (string) $user->getKey();
    }
}
