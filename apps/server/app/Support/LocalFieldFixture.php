<?php

namespace App\Support;

/**
 * Well-known local development identities shared with the field app.
 *
 * Ordinary development seed data, written by
 * `php artisan meridian:seed-local-field-fixture` and reached by signing in as
 * that user. It once backed the `local.field` shared-token middleware, which is
 * removed (M16.11): the fixture no longer authenticates anything, it only gives
 * a developer a populated organization, event, staff record, and device to sign
 * in against.
 */
final class LocalFieldFixture
{
    public const ORGANIZATION_ID = '88888888-8888-4888-8888-888888888888';

    public const EVENT_ID = '11111111-1111-4111-8111-111111111111';

    public const USER_ID = '22222222-2222-4222-8222-222222222222';

    public const STAFF_ID = '33333333-3333-4333-8333-333333333333';

    public const DEVICE_ID = '44444444-4444-4444-8444-444444444444';

    public const NODE_ID = '55555555-5555-4555-8555-555555555555';

    public const DEPARTMENT_ID = '66666666-6666-4666-8666-666666666666';

    public const TEAM_ID = '77777777-7777-4777-8777-777777777777';

    /*
     * The Rangers Dirt team.
     *
     * Pinned because the client's shift fixtures put the signed-in staff member
     * on a Dirt shift, and a Field Report filed on shift carries the team whose
     * shift it was (technical spec 17.3). Without this row the node refused
     * every on-shift report with "Field Report team is not active in the
     * supplied department" — the client described a team the server had never
     * heard of.
     *
     * Unlike the default teams below, this one is safe to pin: it is not any
     * department's default team, so nothing in `departments.default_team_id`
     * points at it.
     */
    public const RANGERS_DIRT_TEAM_ID = '77777777-7777-4777-8777-777777777771';

    /*
     * The remaining departments the client's department switcher offers
     * (`apps/client/src/department-teams/fixtureDepartmentAccess.ts`).
     *
     * The client switcher is fixture data; the server's permission checks are
     * not. Before these existed, switching to "Organizer" changed what the
     * client showed without changing what the server would allow, so an
     * organizer-only action such as a branding logo upload was offered and then
     * refused with a 403. These ids exist so both sides describe the same
     * person in the same organization.
     *
     * Only department ids are pinned. Teams are reached through each
     * department's own default team, which TEAM-002 creates automatically;
     * pinning team ids as well would mean deleting that auto-created team,
     * and `departments.default_team_id` references it.
     */
    public const ORGANIZER_DEPARTMENT_ID = '22222222-2222-4222-8222-222222222201';

    public const GATE_DEPARTMENT_ID = '22222222-2222-4222-8222-222222222202';

    public const DPW_DEPARTMENT_ID = '22222222-2222-4222-8222-222222222203';

    public const USER_EMAIL = 'local-field@meridian.test';

    public const USER_NAME = 'Local Field Author';

    /*
     * A second sign-in whose whole authority is leading the Rangers Dirt team
     * (M18.16 QA). The author account above holds department lead, organizer,
     * and IC roles at once, which makes it useless for proving what a *team
     * lead alone* can reach: this account edits Dirt shifts and assigns their
     * credit policy, and can administer nothing else.
     */
    public const TEAM_LEAD_USER_ID = '22222222-2222-4222-8222-222222222212';

    public const TEAM_LEAD_STAFF_ID = '33333333-3333-4333-8333-333333333313';

    public const TEAM_LEAD_USER_EMAIL = 'local-team-lead@meridian.test';

    public const TEAM_LEAD_USER_NAME = 'Local Team Lead';

    /*
     * Two credit policies, so the shift edit surface has ratios to assign the
     * moment the fixture lands (SHIFT-010; M18.16): the organization default
     * every uncommitted shift falls back to, and a second rate worth choosing
     * over it.
     */
    public const CREDIT_POLICY_STANDARD_ID = '99999999-9999-4999-8999-999999999901';

    public const CREDIT_POLICY_OVERNIGHT_ID = '99999999-9999-4999-8999-999999999902';
}
