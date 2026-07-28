<?php

namespace App\Support;

/**
 * Well-known local QA identities shared with the field app development session.
 *
 * Used only when `meridian.local_field_api` is enabled for Alpha 1 local
 * exercise of Field Report text/photo command upload without Sanctum.
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
}
