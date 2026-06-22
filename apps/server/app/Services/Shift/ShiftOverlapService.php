<?php

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use Illuminate\Support\Collection;

/**
 * Detect schedule overlaps for shift signup and assignment (SHIFT-014).
 */
class ShiftOverlapService
{
    /**
     * @return list<ShiftOverlapWarning>
     */
    public function warningsFor(Staff $staff, Shift $shift, ?string $excludeShiftId = null): array
    {
        return $this->overlappingShifts($staff, $shift, $excludeShiftId)
            ->map(fn (Shift $overlappingShift): ShiftOverlapWarning => new ShiftOverlapWarning($overlappingShift))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Shift>
     */
    public function overlappingShifts(Staff $staff, Shift $shift, ?string $excludeShiftId = null): Collection
    {
        return ShiftAssignment::query()
            ->active()
            ->where('staff_id', $staff->id)
            ->whereHas('shift', function ($query) use ($shift, $excludeShiftId): void {
                $query->active()
                    ->where('starts_at', '<', $shift->ends_at)
                    ->where('ends_at', '>', $shift->starts_at);

                if ($excludeShiftId !== null) {
                    $query->where('id', '!=', $excludeShiftId);
                }
            })
            ->with('shift')
            ->get()
            ->pluck('shift')
            ->filter(fn (?Shift $overlappingShift): bool => $overlappingShift !== null)
            ->unique('id')
            ->values();
    }
}
