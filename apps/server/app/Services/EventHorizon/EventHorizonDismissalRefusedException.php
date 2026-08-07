<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use RuntimeException;

/**
 * A hide request that the node refuses (HORIZON-013; technical spec 21D.8).
 *
 * The condition is enforced here rather than by withholding the control: a
 * dismissal available while work is outstanding would be an opt-out of the
 * preparation the surface exists to drive.
 */
final class EventHorizonDismissalRefusedException extends RuntimeException {}
