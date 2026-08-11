<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use RuntimeException;

/**
 * Thrown when a node in production or event mode is asked to serve while a
 * required secret is missing or still set to a sample value (technical spec
 * 26.2, "refuse to boot with default secrets").
 *
 * The message aggregates every reason so the refusal states what is wrong and
 * what to do about it. It contains variable names and remedies and no values:
 * this message is rendered to an HTTP client and written to container logs.
 */
class DefaultSecretsException extends RuntimeException
{
    public function __construct(public readonly SecretReadiness $readiness)
    {
        $reasons = $readiness->reasons();

        $message = $reasons === []
            ? 'This node refuses to start: a required secret is missing or is still set to a sample value.'
            : 'This node refuses to start until its secrets are configured. '.implode(' ', $reasons);

        parent::__construct($message);
    }
}
