<?php

namespace App\Services\Deployments;

use RuntimeException;

class DeploymentAssignmentException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to assign deployments for this shift.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept deployment assignment changes.');
    }

    public static function noActiveAssignment(): self
    {
        return new self('Staff must have an active assignment for this shift before deployment assignment.');
    }

    public static function deploymentOutsideShiftScope(): self
    {
        return new self('Deployment must belong to the same event and department as the shift.');
    }

    public static function archivedDeployment(): self
    {
        return new self('Archived deployments cannot be assigned.');
    }
}
