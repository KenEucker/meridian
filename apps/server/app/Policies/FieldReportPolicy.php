<?php

namespace App\Policies;

use App\Models\FieldReport;
use App\Models\User;

/**
 * Field Report authorization for M9.1 author visibility (FR-004, FR-006, FR-007).
 *
 * Authors may view their own submitted reports. Non-authors are denied by
 * default, including department leads, organizers, and shift leads (FR-006;
 * technical spec 17.6). Event-wide IC visibility (`field_reports.view_event`)
 * is deferred to M9.5. Updates and deletes are always denied (FR-007).
 */
class FieldReportPolicy
{
    public function view(User $user, FieldReport $fieldReport): bool
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
