<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use RuntimeException;

/**
 * A refused organization configuration edit (M18.14; ORG-020, ORG-021).
 */
final class OrganizationConfigurationException extends RuntimeException
{
    /** Whether this is the node-authority refusal rather than a bad value. */
    public bool $isAuthorityRefusal = false;

    public static function invalid(string $message): self
    {
        return new self($message);
    }

    /**
     * Organization configuration is governance data and central is the node
     * that owns it (ORG-021). An on-site node refuses the edit outright rather
     * than queueing an operation, because central will push the configuration
     * down again and an on-site edit would be a second source of truth.
     */
    public static function notCentral(string $nodeRole): self
    {
        $exception = new self(sprintf(
            'Organization configuration is maintained on the central node. This node is %s and does not hold configuration authority.',
            $nodeRole === '' ? 'unconfigured' : sprintf('an %s node', $nodeRole),
        ));
        $exception->isAuthorityRefusal = true;

        return $exception;
    }
}
