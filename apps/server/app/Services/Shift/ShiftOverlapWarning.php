<?php

namespace App\Services\Shift;

use App\Models\Shift;

/**
 * Schedule overlap advisory for shift signup or assignment (SHIFT-014).
 */
final class ShiftOverlapWarning
{
    public const CODE = 'schedule_overlap';

    public function __construct(public readonly Shift $overlappingShift) {}

    public function message(): string
    {
        return sprintf(
            'This shift overlaps with %s (%s – %s).',
            $this->overlappingShift->title,
            $this->overlappingShift->starts_at->toDateTimeString(),
            $this->overlappingShift->ends_at->toDateTimeString(),
        );
    }
}
