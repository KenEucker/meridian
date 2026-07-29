<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Support\Str;

/**
 * Resolves the records an imported row names by the identifiers an operator can
 * read off a spreadsheet (technical spec 22.2).
 *
 * Import files never carry identifiers. An operator building a shift schedule in
 * a spreadsheet knows the organization slug, the event slug, and the department
 * and team codes; they do not know a UUID, and a file full of them could not be
 * checked by eye. Every lookup here is therefore by human-readable name, scoped
 * to its parent so a code reused across organizations still lands in the right
 * place, and case-insensitive because casing is not something worth failing an
 * import over.
 *
 * A lookup that finds nothing returns null rather than throwing, because a row
 * naming a department that does not exist is one skipped row, not a broken file.
 */
final class ImportLookup
{
    public function organization(string $slug): ?Organization
    {
        return Organization::query()
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)])
            ->first();
    }

    public function event(Organization $organization, string $slug): ?Event
    {
        return Event::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(slug) = ?', [Str::lower($slug)])
            ->first();
    }

    public function department(Organization $organization, string $code): ?Department
    {
        return Department::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(code) = ?', [Str::lower($code)])
            ->first();
    }

    public function team(Department $department, string $code): ?Team
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->whereRaw('lower(code) = ?', [Str::lower($code)])
            ->first();
    }

    /**
     * Staff email addresses are unique, so an address identifies one person or
     * nobody.
     */
    public function staff(string $email): ?Staff
    {
        return Staff::query()
            ->whereRaw('lower(email) = ?', [Str::lower($email)])
            ->first();
    }
}
