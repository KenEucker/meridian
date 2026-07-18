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

    public const USER_EMAIL = 'local-field@meridian.test';

    public const USER_NAME = 'Local Field Author';
}
