<?php

declare(strict_types=1);

namespace App\Services\Imports;

use RuntimeException;

/**
 * Raised when an import file cannot be read at all — empty content, a missing
 * required header column — as opposed to a single row that cannot be applied.
 *
 * Row-level problems are never thrown: they are reported as skipped rows so one
 * bad line does not abort the file (technical spec 22.2).
 */
final class ImportException extends RuntimeException {}
