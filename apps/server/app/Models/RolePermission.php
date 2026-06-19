<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UUID-keyed pivot for the permission role <-> permission link so attached
 * rows receive a collision-free identifier per the canonical identifier
 * policy (data/API spec section 4.1). The table only records a creation
 * timestamp, so updates are disabled.
 */
class RolePermission extends Pivot
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'role_permissions';

    protected $keyType = 'string';
}
