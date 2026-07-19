import { meridianJson } from "@/api/meridianApi";
import type { OfflineFieldReport } from "@/field-reports/offlineFieldReport";

export interface SubmittedFieldReportAcceptance {
  readonly id: string;
  readonly fraNumber: string | null;
  readonly serverReceivedAt: string | null;
  readonly syncStatus: string;
}

interface SubmitFieldReportResponse {
  readonly id: string;
  readonly fra_number: string | null;
  readonly server_received_at: string | null;
  readonly sync_status: string;
}

/** POST /api/commands/submit-field-report for a locally finalized report. */
export async function submitFieldReportCommand(
  report: OfflineFieldReport,
): Promise<SubmittedFieldReportAcceptance> {
  const response = await meridianJson<SubmitFieldReportResponse>(
    "/api/commands/submit-field-report",
    {
      method: "POST",
      body: JSON.stringify({
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
        origin_node_id: report.originNodeId,
      }),
    },
  );

  return {
    id: response.id,
    fraNumber: response.fra_number,
    serverReceivedAt: response.server_received_at,
    syncStatus: response.sync_status,
  };
}
