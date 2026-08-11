<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use Closure;

/**
 * One secret a Meridian node must hold before it may serve production or event
 * traffic (technical spec 7.4, 26.2).
 *
 * A requirement names the environment variable an operator sets, the Laravel
 * configuration key its effective value is read from, and the values that mean
 * "still the sample". It never holds a value: nothing in this namespace reads a
 * secret into a message, a log line, or a console table.
 *
 * Two flags decide how a failure is treated:
 *
 *   - {@see $required} — the node cannot operate without it at all, so a blank
 *     value is a refusal and not merely an unconfigured optional service. Only
 *     `APP_KEY` carries this: everything else here is either written by setup
 *     into node configuration or belongs to a service the deployment may not
 *     run.
 *   - {@see $generatable} — Meridian owns the secret and can mint a new one.
 *     A database password is not generatable, because the database already has
 *     one and inventing a second would leave the node unable to connect.
 *
 * {@see $applicable} keeps the safeguard from refusing a node over a secret for
 * a service it does not use. A deployment on PostgreSQL with no Redis, no SMTP
 * credentials, and no OAuth providers configured is a complete deployment, and
 * the Redis password it never set is not a default secret.
 */
final class SecretRequirement
{
    /**
     * @param  list<string>  $defaultValues  Values that mean the secret is still the sample one.
     * @param  (Closure(): bool)|null  $applicable  Whether this node uses the thing the secret protects.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $configKey,
        public readonly bool $required,
        public readonly bool $generatable,
        public readonly array $defaultValues,
        public readonly string $remedy,
        private readonly ?Closure $applicable = null,
    ) {}

    public function applies(): bool
    {
        return $this->applicable === null || ($this->applicable)();
    }

    /**
     * Whether a configured value is one of the values that count as a default.
     *
     * Comparison is case-insensitive and trimmed, because a sample copied by
     * hand arrives with different capitalisation and stray whitespace far more
     * often than it arrives changed.
     */
    public function isDefaultValue(string $value): bool
    {
        $candidate = strtolower(trim($value));

        foreach ([...$this->defaultValues, ...SecretInventory::UNIVERSAL_PLACEHOLDERS] as $default) {
            if ($candidate === strtolower(trim($default))) {
                return true;
            }
        }

        return false;
    }
}
