<?php

namespace App\Services\Attendance;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\HoursWorked;

final readonly class AttendanceCheckOutResult
{
    public function __construct(
        public AttendanceOperation $operation,
        public AttendanceRecord $record,
        public HoursWorked $hoursWorked,
        public bool $createdStateChange,
        public bool $createdHours,
    ) {}
}
