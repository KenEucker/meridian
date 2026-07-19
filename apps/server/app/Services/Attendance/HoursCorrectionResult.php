<?php

namespace App\Services\Attendance;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\HoursWorked;

final readonly class HoursCorrectionResult
{
    public function __construct(
        public AttendanceOperation $operation,
        public AttendanceRecord $record,
        public HoursWorked $hoursWorked,
        public bool $createdCorrection,
    ) {}
}
