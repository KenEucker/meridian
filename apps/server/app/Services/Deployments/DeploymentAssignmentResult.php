<?php

namespace App\Services\Deployments;

use App\Models\CurrentDeploymentAssignment;

readonly class DeploymentAssignmentResult
{
    public function __construct(
        public CurrentDeploymentAssignment $assignment,
        public bool $createdStateChange,
    ) {}
}
