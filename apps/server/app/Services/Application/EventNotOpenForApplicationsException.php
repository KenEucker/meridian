<?php

namespace App\Services\Application;

use RuntimeException;

/**
 * Thrown when an event cannot accept new applications (for example, an archived
 * event). Submission to such an event must not create a record.
 */
class EventNotOpenForApplicationsException extends RuntimeException {}
