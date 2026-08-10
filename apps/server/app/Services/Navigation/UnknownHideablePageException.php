<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use RuntimeException;

/**
 * A page-visibility preference named a page the catalog does not know
 * (M18.69).
 *
 * Refused rather than stored. A row naming a page nothing renders is silent
 * rubbish that would outlive the client that wrote it, and the honest answer to
 * a client asking about a page this build has never heard of is that it does
 * not exist.
 */
final class UnknownHideablePageException extends RuntimeException {}
