<?php

declare(strict_types=1);

namespace App\Domain\Marketing;

/**
 * What became of an organization interest submission (PUBLIC-003, PUBLIC-005).
 *
 * Three outcomes, and only one of them writes a row.
 */
enum OrganizationInterestOutcome: string
{
    /** An inquiry was written and is waiting in God Mode. */
    case Recorded = 'recorded';

    /**
     * The submission tripped the hidden-field trap and was thrown away.
     *
     * The caller answers this exactly as it answers {@see self::Recorded}. A
     * client that filled in a field no human can see is a client that is not
     * reading the response, and a distinguishable answer would teach the one
     * that is.
     */
    case Discarded = 'discarded';

    /**
     * The submission arrived faster than a person could have typed it.
     *
     * Unlike the hidden-field trap this is answered honestly, by returning the
     * visitor to their own form with what they wrote still in it. Timing is a
     * weaker signal than a hidden field — a fast typist pasting prepared text
     * is a real person — and PUBLIC-005 asks for protection that does not block
     * legitimate use. Asking somebody to press the button again costs them a
     * second; silently dropping what they wrote costs them the conversation.
     */
    case TooFast = 'too_fast';
}
