<?php

namespace App\Services\Training;

use RuntimeException;

class TrainingPrerequisiteException extends RuntimeException
{
    public static function selfReference(): self
    {
        return new self('A training cannot be a prerequisite of itself.');
    }

    public static function differentOrganization(): self
    {
        return new self('A prerequisite training must belong to the same organization.');
    }

    public static function duplicate(): self
    {
        return new self('This prerequisite relationship already exists.');
    }

    public static function cycle(): self
    {
        return new self('Adding this prerequisite would create a prerequisite cycle.');
    }
}
