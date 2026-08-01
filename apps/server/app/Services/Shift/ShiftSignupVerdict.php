<?php

namespace App\Services\Shift;

/**
 * Whether one staff member may sign up for one shift right now, and why not
 * (SHIFT-011, SHIFT-018; requirements 3.12).
 *
 * This is the same answer `ShiftSignupService::signUp` acts on, asked without
 * acting. The shift board needs it because SHIFT-018 requires an unavailable
 * shift to say why it is unavailable, and the only honest way to say that is to
 * run the rules the command runs rather than a second copy of them written for
 * display.
 */
final class ShiftSignupVerdict
{
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $reasonCode,
        public readonly ?string $message,
    ) {}

    public static function eligible(): self
    {
        return new self(true, null, null);
    }

    public static function denied(ShiftSignupException $exception): self
    {
        return new self(false, $exception->reasonCode, $exception->getMessage());
    }
}
