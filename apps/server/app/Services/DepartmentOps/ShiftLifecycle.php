<?php

declare(strict_types=1);

namespace App\Services\DepartmentOps;

use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Where a shift is in its own life, as of a moment (technical spec 20.5).
 *
 * Four answers and no fifth: cancelled, upcoming, active, completed. Nothing
 * stores this — it is the shift's own window read against a clock — which is
 * precisely why it belongs in one place. Two surfaces deriving it separately
 * would eventually disagree about when a shift stops being upcoming, and the
 * disagreement would surface as a desk offering a control the node refuses.
 */
final class ShiftLifecycle
{
    public const CANCELLED = 'cancelled';

    public const UPCOMING = 'upcoming';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public static function of(Shift $shift, Carbon $now): string
    {
        if ($shift->isCancelled()) {
            return self::CANCELLED;
        }

        if ($shift->starts_at !== null && $now->lessThan($shift->starts_at)) {
            return self::UPCOMING;
        }

        if ($shift->ends_at !== null && $now->greaterThan($shift->ends_at)) {
            return self::COMPLETED;
        }

        return self::ACTIVE;
    }
}
