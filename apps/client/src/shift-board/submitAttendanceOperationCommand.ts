import { meridianJson } from "@/api/meridianApi";
import type {
  AttendanceOperationType,
  OfflineAttendanceOperation,
} from "@/shift-board/offlineAttendanceOperation";

export interface SubmittedAttendanceOperationAcceptance {
  readonly operationUuid: string;
  readonly operationId: string;
  readonly attendanceRecordId: string;
  readonly hoursWorkedId: string | null;
  readonly currentState: string;
  readonly serverReceivedAt: string | null;
  readonly createdStateChange: boolean;
  readonly createdHours: boolean | null;
}

interface SubmitAttendanceOperationResponse {
  readonly operation_uuid: string;
  readonly operation_id: string;
  readonly attendance_record_id: string;
  readonly hours_worked_id?: string;
  readonly current_state: string;
  readonly server_received_at: string | null;
  readonly created_state_change: boolean;
  readonly created_hours?: boolean;
}

const COMMAND_PATHS: Record<AttendanceOperationType, string> = {
  check_in: "/api/commands/check-in-staff",
  check_out: "/api/commands/check-out-staff",
  mark_no_show: "/api/commands/mark-no-show",
};

export async function submitAttendanceOperationCommand(
  operation: OfflineAttendanceOperation,
): Promise<SubmittedAttendanceOperationAcceptance> {
  const body: Record<string, string | null> = {
    operation_uuid: operation.operationUuid,
    shift_id: operation.shiftId,
    staff_id: operation.staffId,
    device_created_at: operation.deviceCreatedAt,
    origin_device_id: operation.originDeviceId,
    origin_node_id: operation.originNodeId,
  };

  if (operation.operationType === "check_out") {
    body.actual_started_at = operation.actualStartedAt;
    body.actual_ended_at = operation.actualEndedAt;
  }

  const response = await meridianJson<SubmitAttendanceOperationResponse>(
    COMMAND_PATHS[operation.operationType],
    {
      method: "POST",
      body: JSON.stringify(body),
    },
  );

  return {
    operationUuid: response.operation_uuid,
    operationId: response.operation_id,
    attendanceRecordId: response.attendance_record_id,
    hoursWorkedId: response.hours_worked_id ?? null,
    currentState: response.current_state,
    serverReceivedAt: response.server_received_at,
    createdStateChange: response.created_state_change,
    createdHours: response.created_hours ?? null,
  };
}
