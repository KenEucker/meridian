<?php

namespace App\Services\Marketing;

use App\Domain\Marketing\OrganizationInterestOutcome;
use App\Models\OrganizationInquiry;

/**
 * The result of one organization interest submission (M18.23; PUBLIC-003,
 * PUBLIC-005).
 *
 * `inquiry` is present only for {@see OrganizationInterestOutcome::Recorded}.
 * A discarded submission has no record because PUBLIC-003 is about what a
 * submission creates, and a submission Meridian decided not to believe should
 * create less rather than more.
 */
final readonly class OrganizationInterestSubmission
{
    public function __construct(
        public OrganizationInterestOutcome $outcome,
        public ?OrganizationInquiry $inquiry = null,
    ) {}

    /**
     * Whether the surface should show the visitor the confirmation.
     *
     * True for a discarded submission as well as a recorded one: the hidden
     * field is only a trap while the two are indistinguishable.
     */
    public function isAccepted(): bool
    {
        return in_array($this->outcome, [
            OrganizationInterestOutcome::Recorded,
            OrganizationInterestOutcome::Discarded,
        ], true);
    }
}
