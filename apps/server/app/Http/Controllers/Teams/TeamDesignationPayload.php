<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Models\Department;
use App\Models\TeamDesignation;

/**
 * The wire shape of a department's team designations (M18.12; TEAM-016).
 *
 * One row per designatable function, undesignated functions included with a
 * null team: the administration surface renders the five functions whether or
 * not anything is designated yet, so the read answers with the whole frame
 * rather than only the rows that exist.
 */
final class TeamDesignationPayload
{
    /**
     * @return list<array{
     *     function_code: string,
     *     function_label: string,
     *     role_code: string,
     *     team_id: string|null,
     *     team_name: string|null
     * }>
     */
    public static function forDepartment(Department $department): array
    {
        $active = TeamDesignation::query()
            ->active()
            ->where('department_id', $department->id)
            ->with('team')
            ->get()
            ->keyBy('function_code');

        $roleCodes = TeamDesignation::functionRoleCodes();

        return array_map(static function (string $functionCode) use ($active, $roleCodes): array {
            $designation = $active->get($functionCode);

            return [
                'function_code' => $functionCode,
                'function_label' => TeamDesignation::functionLabel($functionCode),
                'role_code' => $roleCodes[$functionCode],
                'team_id' => $designation !== null ? (string) $designation->team_id : null,
                'team_name' => $designation?->team?->name,
            ];
        }, TeamDesignation::departmentFunctions());
    }
}
