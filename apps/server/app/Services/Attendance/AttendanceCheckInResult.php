<?php

namespace App\Services\Attendance;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;

final readonly class AttendanceCheckInResult
{
    public function __construct(
        public AttendanceOperation $operation,
        public AttendanceRecord $record,
        public bool $createdStateChange,
    ) {}
}
