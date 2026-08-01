// The attendance command request bodies (M10.5; M16.10).
//
// Since M16.10 this module builds the body and stops there: the shared command
// outbox owns the request, the retry, and the acceptance (technical spec 11A.5).
// The command type an operation maps to is part of the operation, so it is
// resolved here rather than at the point of sending.

import type { MeridianCommandType } from "@/outbox/commandCatalog";
import type {
  AttendanceOperationType,
  OfflineAttendanceOperation,
} from "@/shift-board/offlineAttendanceOperation";

const COMMAND_TYPES: Record<AttendanceOperationType, MeridianCommandType> = {
  check_in: "check-in-staff",
  check_out: "check-out-staff",
  mark_no_show: "mark-no-show",
};

export function attendanceCommandType(
  operationType: AttendanceOperationType,
): MeridianCommandType {
  return COMMAND_TYPES[operationType];
}

/** POST body for the attendance command this operation maps to. */
export function attendanceCommandPayload(
  operation: OfflineAttendanceOperation,
): Readonly<Record<string, unknown>> {
  const body: Record<string, unknown> = {
    operation_uuid: operation.operationUuid,
    shift_id: operation.shiftId,
    staff_id: operation.staffId,
    device_created_at: operation.deviceCreatedAt,
    origin_device_id: operation.originDeviceId,
  };

  // Omitted rather than sent null when the device knows no node: the node that
  // receives the command records itself as the origin (M16.21).
  if (operation.originNodeId !== null) {
    body.origin_node_id = operation.originNodeId;
  }

  if (operation.operationType === "check_out") {
    body.actual_started_at = operation.actualStartedAt;
    body.actual_ended_at = operation.actualEndedAt;
  }

  return Object.freeze(body);
}
