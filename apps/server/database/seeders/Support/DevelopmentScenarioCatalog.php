<?php

namespace Database\Seeders\Support;

/**
 * Canonical development seed scenario from development process section 13.3.
 */
final class DevelopmentScenarioCatalog
{
    public const ORGANIZATION_NAME = 'Idaho Burners';

    public const ORGANIZATION_SLUG = 'idaho-burners';

    public const EVENT_NAME = 'Idaho Decompression 2026';

    public const EVENT_SLUG = 'idaho-decompression-2026';

    public const EVENT_TIMEZONE = 'America/Boise';

    public const DEFAULT_PASSWORD = 'password';

    /**
     * @return list<array{
     *     key: string,
     *     user_name: string,
     *     email: string,
     *     org_status: string,
     *     department_code: string|null,
     *     team_code: string|null,
     *     department_status: string|null,
     *     grants: list<array{role: string, event_scoped: bool}>
     * }>
     */
    public static function personas(): array
    {
        return [
            [
                'key' => 'vera',
                'user_name' => 'Vera Staff',
                'email' => 'vera.staff@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'sam',
                'user_name' => 'Sam Shiftlead',
                'email' => 'sam.shiftlead@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [
                    ['role' => 'shift_lead', 'event_scoped' => false],
                ],
            ],
            [
                'key' => 'dana',
                'user_name' => 'Dana Departmentlead',
                'email' => 'dana.departmentlead@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [
                    ['role' => 'department_lead', 'event_scoped' => false],
                ],
            ],
            [
                'key' => 'olive',
                'user_name' => 'Olive Organizer',
                'email' => 'olive.organizer@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'ORGANIZERS',
                'team_code' => 'DEFAULT',
                'department_status' => null,
                'grants' => [
                    ['role' => 'organizer', 'event_scoped' => false],
                ],
            ],
            [
                'key' => 'ingrid',
                'user_name' => 'Ingrid ICLead',
                'email' => 'ingrid.iclead@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'ORGANIZERS',
                'team_code' => 'COMMAND',
                'department_status' => null,
                'grants' => [
                    ['role' => 'ic_lead', 'event_scoped' => true],
                ],
            ],
            [
                'key' => 'omar',
                'user_name' => 'Omar ICOperator',
                'email' => 'omar.icoperator@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'GATE',
                'team_code' => 'OPERATOR',
                'department_status' => null,
                'grants' => [
                    ['role' => 'ic_operator', 'event_scoped' => true],
                ],
            ],
            [
                'key' => 'ivy',
                'user_name' => 'Ivy ICViewer',
                'email' => 'ivy.icviewer@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'DPW',
                'team_code' => 'LOGISTICS',
                'department_status' => null,
                'grants' => [
                    ['role' => 'ic_viewer', 'event_scoped' => true],
                ],
            ],
            [
                'key' => 'gwen',
                'user_name' => 'Gwen Godmode',
                'email' => 'gwen.godmode@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => null,
                'team_code' => null,
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'debbie',
                'user_name' => 'Debbie DNS',
                'email' => 'debbie.dns@idaho-burners.test',
                'org_status' => 'do_not_staff',
                'department_code' => null,
                'team_code' => null,
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'pat',
                'user_name' => 'Pat Prospective',
                'email' => 'pat.prospective@idaho-burners.test',
                'org_status' => 'prospective',
                'department_code' => null,
                'team_code' => null,
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'ira',
                'user_name' => 'Ira Ineligible',
                'email' => 'ira.ineligible@idaho-burners.test',
                'org_status' => 'active',
                'department_code' => 'GATE',
                'team_code' => 'DEFAULT',
                'department_status' => 'ineligible',
                'grants' => [],
            ],
        ];
    }

    /**
     * @return list<array{name: string, code: string, teams: list<array{name: string, code: string}>}>
     */
    public static function departments(): array
    {
        return [
            [
                'name' => 'Organizers',
                'code' => 'ORGANIZERS',
                'teams' => [
                    ['name' => 'Command', 'code' => 'COMMAND'],
                ],
            ],
            [
                'name' => 'Rangers',
                'code' => 'RANGERS',
                'teams' => [
                    ['name' => 'Dirt', 'code' => 'DIRT'],
                ],
            ],
            [
                'name' => 'Gate',
                'code' => 'GATE',
                'teams' => [
                    ['name' => 'Operator', 'code' => 'OPERATOR'],
                ],
            ],
            [
                'name' => 'DPW',
                'code' => 'DPW',
                'teams' => [
                    ['name' => 'Logistics', 'code' => 'LOGISTICS'],
                ],
            ],
        ];
    }
}
