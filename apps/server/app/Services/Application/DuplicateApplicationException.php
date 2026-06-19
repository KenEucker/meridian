<?php

namespace App\Services\Application;

use RuntimeException;

/**
 * Thrown when an applicant already has an active (submitted) application for the
 * same event, to prevent accidental double submission.
 */
class DuplicateApplicationException extends RuntimeException {}
