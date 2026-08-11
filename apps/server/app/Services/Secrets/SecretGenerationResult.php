<?php

declare(strict_types=1);

namespace App\Services\Secrets;

/**
 * What generation did, or could not do, for one secret (technical spec 7.4).
 *
 * A generated secret is never returned or logged here — only the fact that one
 * was written, and where. {@see MANUAL} is the honest outcome for a secret
 * Meridian does not own: a database password belongs to the database, and the
 * only useful thing setup can do about a sample one is say so.
 */
final class SecretGenerationResult
{
    /** A new secret was generated and persisted. */
    public const GENERATED = 'generated';

    /** Already configured; nothing was touched. */
    public const UNCHANGED = 'unchanged';

    /** Needs a human: Meridian cannot mint it, or cannot persist it here. */
    public const MANUAL = 'manual';

    public function __construct(
        public readonly string $name,
        public readonly string $outcome,
        public readonly string $message,
    ) {}

    public static function generated(string $name, string $message): self
    {
        return new self($name, self::GENERATED, $message);
    }

    public static function unchanged(string $name, string $message): self
    {
        return new self($name, self::UNCHANGED, $message);
    }

    public static function manual(string $name, string $message): self
    {
        return new self($name, self::MANUAL, $message);
    }
}
