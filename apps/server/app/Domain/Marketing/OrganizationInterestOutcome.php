<?php

declare(strict_types=1);

namespace App\Domain\Marketing;

/**
 * What became of an organization interest submission (PUBLIC-003, PUBLIC-005).
 *
 * Four outcomes, and only one of them writes a row.
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
     * The submission carried a form token younger than a person could have
     * typed the form in.
     *
     * Answered honestly, with a message asking the visitor to look it over and
     * send it again. Timing is a weaker signal than a hidden field — a fast
     * typist pasting prepared text is a real person — and PUBLIC-005 asks for
     * protection that does not block legitimate use. In a client application
     * nothing is lost by asking: the form is still on screen with everything
     * in it.
     */
    case TooFast = 'too_fast';

    /**
     * The submission carried no usable form token: absent, tampered with, or
     * issued so long ago that the page has been open since before the last
     * deploy.
     *
     * Also answered honestly. A bot posting straight at the endpoint has never
     * asked for a token and meets this, which is the more valuable half of the
     * mechanism; a real visitor meets it only after leaving a tab open for a
     * day, and is told to reload rather than left wondering.
     */
    case StaleForm = 'stale_form';
}
