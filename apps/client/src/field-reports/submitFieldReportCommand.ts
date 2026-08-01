// The `submit-field-report` request body (M9.3; M16.10).
//
// Since M16.10 this module builds the body and stops there: the shared command
// outbox owns the request, the retry, and the acceptance. Building the body at
// queue time rather than at send time is what lets a queued Field Report be sent
// by a process that has never loaded the author catalog — after a restart, or
// from a device whose user has since signed out of a shared workstation.

import type { OfflineFieldReport } from "@/field-reports/offlineFieldReport";

/** POST body for `/api/commands/submit-field-report`. */
export function fieldReportCommandPayload(
  report: OfflineFieldReport,
): Readonly<Record<string, unknown>> {
  const payload: Record<string, unknown> = {
    id: report.id,
    event_id: report.eventId,
    department_id: report.departmentId,
    team_id: report.teamId,
    staff_id: report.staffId,
    temporary_local_number: report.temporaryLocalNumber,
    title: report.title,
    body: report.body,
    device_submitted_at: report.deviceSubmittedAt,
    origin_device_id: report.originDeviceId,
  };

  /*
   * Omitted rather than sent as null when the device does not know a node
   * (M16.22). The node that receives the command records itself as the origin,
   * which is right for every report a browser files; a report replayed from
   * somewhere else names the node it actually came from.
   */
  if (report.originNodeId !== null) {
    payload.origin_node_id = report.originNodeId;
  }

  return Object.freeze(payload);
}
