<?php

namespace App\Services\Staffing;

use RuntimeException;

/**
 * A refusal from the staff profile self-service path (M18.20; VOL-015,
 * VOL-016), in words the staff member who caused it can be shown.
 */
final class StaffProfileSelfException extends RuntimeException {}
