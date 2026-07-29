<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

/**
 * One independent diagnostic check (technical spec 22A.8; SYS-029, SYS-030).
 *
 * Checks are read-only against Meridian state and non-destructive against
 * external services: no messages are sent, no external resources are created,
 * and any probe file a check writes is cleaned up before it returns. A check
 * that cannot actually measure something returns `unknown` rather than
 * pretending it ran (SYS-032).
 *
 * A check throwing is handled by the runner: a required check's failure
 * becomes `critical`, an optional check's becomes `unknown`, and the
 * exception class (never its message, which can carry connection details)
 * goes in the result details.
 */
interface DiagnosticCheck
{
    /** Stable key, e.g. `database.connection`. */
    public function key(): string;

    public function label(): string;

    /** One of the {@see DiagnosticCategory} constants. */
    public function category(): string;

    /**
     * Whether a critical result from this check makes the whole node
     * critical. Optional integrations are not required (SYS-031).
     */
    public function required(): bool;

    public function run(): DiagnosticResult;
}
