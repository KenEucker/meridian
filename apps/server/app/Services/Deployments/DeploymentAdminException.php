<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use RuntimeException;

/**
 * A refusal shown while maintaining a department's deployment options.
 */
final class DeploymentAdminException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
