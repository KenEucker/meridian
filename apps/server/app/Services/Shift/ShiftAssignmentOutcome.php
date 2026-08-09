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
     * @param  bool  $replayed  Whether this outcome is a command the node had
     *                          already applied arriving a second time (M18.54;
     *                          data/API 5.3). The assignment is the one made the
     *                          first time; nothing was written now.
     */
    public function __construct(
        public readonly ShiftAssignment $assignment,
        public readonly array $warnings = [],
        public readonly bool $replayed = false,
    ) {}

    public function hasOverlapWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
