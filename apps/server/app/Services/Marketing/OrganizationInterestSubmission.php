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

    /** Whether the visitor should be shown the confirmation page. */
    public function isAccepted(): bool
    {
        return $this->outcome !== OrganizationInterestOutcome::TooFast;
    }
}
