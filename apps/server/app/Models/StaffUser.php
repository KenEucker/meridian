<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UUID-keyed pivot for the staff <-> user link so attached rows receive a
 * collision-free identifier per the canonical identifier policy (data/API
 * spec section 4.1).
 */
class StaffUser extends Pivot
{
    use HasUuids;

    protected $table = 'staff_user';

    protected $keyType = 'string';
}
