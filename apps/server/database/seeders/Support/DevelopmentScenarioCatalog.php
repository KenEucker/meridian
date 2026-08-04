<?php

namespace Database\Seeders\Support;

/**
 * Canonical development seed scenario from development process section 13.3.
 *
 * Two events, deliberately. The operational surfaces need an event that is
 * running right now — the Logistics Desk, the Operations Center, and the
 * Planning Table are about the shift in front of you, and against a future-only
 * event they open onto nothing. But an event inside its active window freezes
 * organization governance, so branding, policy publication, and configuration
 * could not be exercised at all if that were the only event. The upcoming event
 * is what those surfaces are administered against, and it is also where shift
 * signup lives, because signup windows are open on a schedule nobody is working
 * yet.
 */
final class DevelopmentScenarioCatalog
{
    public const ORGANIZATION_NAME = 'Northwood Collective';

    public const ORGANIZATION_SLUG = 'northwood-collective';

    /** The event running right now, which the operational surfaces open onto. */
    public const EVENT_NAME = 'Emberfall 2026';

    public const EVENT_SLUG = 'emberfall-2026';

    /**
     * The event still ahead, which planning and governance are exercised against.
     *
     * A decompression is the gathering that follows the main burn, so it reads
     * correctly as the next thing on this organization's calendar rather than as
     * a second copy of the one already underway.
     */
    public const UPCOMING_EVENT_NAME = 'Emberfall Decompression 2026';

    public const UPCOMING_EVENT_SLUG = 'emberfall-decompression-2026';

    public const EVENT_TIMEZONE = 'America/Los_Angeles';

    public const DEFAULT_PASSWORD = 'password';

    /**
     * @return list<array{
     *     key: string,
     *     user_name: string,
     *     email: string,
     *     org_status: string,
     *     department_code: string|null,
     *     team_code: string|null,
     *     crew_team_code?: string|null,
     *     department_status: string|null,
     *     grants: list<array{role: string, event_scoped: bool}>
     * }>
     */
    /**
     * Authority lives on its own teams, and that is not cosmetic.
     *
     * A team grant applies to every member of the team, so putting Sam's
     * department roles on Dirt made every Dirt member a department lead —
     * including Vera, who this catalog describes as regular staff. A scenario
     * where the ordinary-staff persona silently holds every capability cannot be
     * used to test a permission boundary, and it is the kind of wrong that is
     * invisible until somebody trusts it.
     *
     * So the grants hang off `RANGER_LEADS`, `IC_COMMAND`, `GATE_LEADS`, and
     * `DPW_LEADS`, and the people who hold them keep a second membership on the
     * crew team named by `crew_team_code` — because a shift is eligible to one
     * team, and a lead who is not on the crew team cannot be rostered onto the
     * crew's shifts. That is also how a real department is shaped: the leads are
     * on the crew, and being a lead is a separate thing they hold.
     */
    public static function personas(): array
    {
        return [
            [
                'key' => 'vera',
                'user_name' => 'Vera Staff',
                'email' => 'vera.staff@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'sam',
                'user_name' => 'Sam Shiftlead',
                'email' => 'sam.shiftlead@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'RANGER_SHIFT_LEADS',
                'crew_team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [
                    ['role' => 'shift_lead', 'event_scoped' => false],
                    ['role' => 'department_logistics', 'event_scoped' => false],
                    ['role' => 'department_operations', 'event_scoped' => false],
                    ['role' => 'department_administration', 'event_scoped' => false],
                    ['role' => 'department_planning', 'event_scoped' => false],
                ],
            ],
            [
                /*
                 * The narrow team lead (M18.16 QA): leads the Dirt crew and
                 * holds nothing else, so she is what the team-lead permission
                 * boundary is tested *with*. Sam cannot prove that path — his
                 * department_administration opens every team's shifts before
                 * shift_lead gets a say. Tess edits Dirt's shifts and assigns
                 * their credit policy, and is refused everywhere else.
                 *
                 * Her grant hangs on DIRT itself, which the header warns
                 * against for every other role — but shift_lead is the
                 * exception the warning notes: it elevates only memberships
                 * designated `membership_role = 'lead'` (M11.17), so Vera and
                 * the other Dirt members stay exactly as ordinary as this
                 * catalog says they are.
                 */
                'key' => 'tess',
                'user_name' => 'Tess Teamlead',
                'email' => 'tess.teamlead@northwood-collective.test',
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
                'email' => 'dana.departmentlead@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'RANGER_LEADS',
                'crew_team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [
                    ['role' => 'department_lead', 'event_scoped' => false],
                ],
            ],
            [
                'key' => 'olive',
                'user_name' => 'Olive Organizer',
                'email' => 'olive.organizer@northwood-collective.test',
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
                'email' => 'ingrid.iclead@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'IC_COMMAND',
                'crew_team_code' => 'COMMAND',
                'department_status' => null,
                'grants' => [
                    ['role' => 'ic_lead', 'event_scoped' => true],
                ],
            ],
            [
                'key' => 'omar',
                'user_name' => 'Omar ICOperator',
                'email' => 'omar.icoperator@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'IC_OPERATOR',
                'department_status' => null,
                'grants' => [
                    ['role' => 'ic_operator', 'event_scoped' => true],
                ],
            ],
            [
                'key' => 'ivy',
                'user_name' => 'Ivy ICViewer',
                'email' => 'ivy.icviewer@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'IC_VIEWER',
                'department_status' => null,
                'grants' => [
                    ['role' => 'ic_viewer', 'event_scoped' => true],
                ],
            ],
            [
                'key' => 'gwen',
                'user_name' => 'Gwen Godmode',
                'email' => 'gwen.godmode@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => null,
                'team_code' => null,
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'debbie',
                'user_name' => 'Debbie DNS',
                'email' => 'debbie.dns@northwood-collective.test',
                'org_status' => 'do_not_staff',
                'department_code' => null,
                'team_code' => null,
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'pat',
                'user_name' => 'Pat Prospective',
                'email' => 'pat.prospective@northwood-collective.test',
                'org_status' => 'prospective',
                'department_code' => null,
                'team_code' => null,
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'ira',
                'user_name' => 'Ira Ineligible',
                'email' => 'ira.ineligible@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'GATE',
                'team_code' => 'DEFAULT',
                'department_status' => 'ineligible',
                'grants' => [],
            ],
            /*
             * Bodies for the operational scenario.
             *
             * The eleven personas above cover authority — one holder per role,
             * which is what a permission test needs. A desk needs something
             * else: enough people on one team to be in different states at the
             * same time, because every refusal the Logistics Desk can produce is
             * a property of a person rather than of a role. Somebody has to be
             * on-site with no assignment for an unscheduled addition to be
             * offered, somebody has to be holding a radio for the off-site block
             * to appear, and somebody has to have missed a shift for a no-show
             * to be on screen. One person cannot be all of those at once.
             */
            [
                'key' => 'nora',
                'user_name' => 'Nora Newstaff',
                'email' => 'nora.newstaff@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'felix',
                'user_name' => 'Felix Fieldhand',
                'email' => 'felix.fieldhand@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'quinn',
                'user_name' => 'Quinn Quartermaster',
                'email' => 'quinn.quartermaster@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'DIRT',
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'mira',
                'user_name' => 'Mira Commandstaff',
                'email' => 'mira.commandstaff@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'RANGERS',
                'team_code' => 'COMMAND',
                'department_status' => null,
                'grants' => [],
            ],
            [
                'key' => 'gabe',
                'user_name' => 'Gabe Gatekeeper',
                'email' => 'gabe.gatekeeper@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'GATE',
                'team_code' => 'GATE_LEADS',
                'crew_team_code' => 'OPERATOR',
                'department_status' => null,
                'grants' => [
                    ['role' => 'department_lead', 'event_scoped' => false],
                    ['role' => 'department_logistics', 'event_scoped' => false],
                ],
            ],
            [
                'key' => 'dex',
                'user_name' => 'Dex Dpw',
                'email' => 'dex.dpw@northwood-collective.test',
                'org_status' => 'active',
                'department_code' => 'DPW',
                'team_code' => 'DPW_LEADS',
                'crew_team_code' => 'LOGISTICS',
                'department_status' => null,
                'grants' => [
                    ['role' => 'department_lead', 'event_scoped' => false],
                    ['role' => 'department_logistics', 'event_scoped' => false],
                ],
            ],
        ];
    }

    /**
     * Persona keys grouped by the operational role the scenario gives them.
     *
     * Named rather than positional so the seeders below read as a description of
     * the scenario instead of as array arithmetic, and so a persona can be moved
     * between roles in one place.
     */
    public const RANGER_DIRT_CREW = ['vera', 'nora', 'felix', 'quinn'];

    public const RANGER_COMMAND_CREW = ['ingrid', 'omar', 'ivy', 'mira'];

    /**
     * @return list<array{name: string, code: string, teams: list<array{name: string, code: string}>}>
     */
    public static function departments(): array
    {
        return [
            [
                'name' => 'Organizers',
                'code' => 'ORGANIZERS',
                'teams' => [],
            ],
            [
                'name' => 'Rangers',
                'code' => 'RANGERS',
                'teams' => [
                    ['name' => 'Dirt', 'code' => 'DIRT'],
                    ['name' => 'Command', 'code' => 'COMMAND'],
                    ['name' => 'Ranger Leads', 'code' => 'RANGER_LEADS'],
                    ['name' => 'Ranger Shift Leads', 'code' => 'RANGER_SHIFT_LEADS'],
                    ['name' => 'IC Command', 'code' => 'IC_COMMAND'],
                    ['name' => 'IC Operators', 'code' => 'IC_OPERATOR'],
                    ['name' => 'IC Viewers', 'code' => 'IC_VIEWER'],
                ],
            ],
            [
                'name' => 'Gate',
                'code' => 'GATE',
                'teams' => [
                    ['name' => 'Operator', 'code' => 'OPERATOR'],
                    ['name' => 'Gate Leads', 'code' => 'GATE_LEADS'],
                ],
            ],
            [
                'name' => 'DPW',
                'code' => 'DPW',
                'teams' => [
                    ['name' => 'Logistics', 'code' => 'LOGISTICS'],
                    ['name' => 'DPW Leads', 'code' => 'DPW_LEADS'],
                ],
            ],
        ];
    }
}
