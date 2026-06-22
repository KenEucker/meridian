<?php

namespace App\Services\Shift;

use App\Models\ShiftAssignment;

/**
 * Result of a successful shift signup or lead assignment, including advisory warnings.
 */
final class ShiftAssignmentOutcome
{
    /**
     * @param  list<ShiftOverlapWarning>  $warnings
     */
    public function __construct(
        public readonly ShiftAssignment $assignment,
        public readonly array $warnings = [],
    ) {}

    public function hasOverlapWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
