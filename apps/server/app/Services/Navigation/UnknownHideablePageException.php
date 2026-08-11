<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use RuntimeException;

/**
 * A page-visibility preference named a page neither catalog knows (M18.69).
 *
 * One exception for both questions — putting a page away, and keeping it out of
 * the menus — because the fault is the same in either case and so is the
 * remedy. The message says which catalog was asked.
 *
 * Refused rather than stored. A row naming a page nothing renders is silent
 * rubbish that would outlive the client that wrote it, and the honest answer to
 * a client asking about a page this build has never heard of is that it does
 * not exist.
 */
final class UnknownHideablePageException extends RuntimeException {}
