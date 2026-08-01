import { meridianJson } from "@/api/meridianApi";
import type { FieldSessionContext } from "@/field-reports/fieldSession";
import type {
  FieldReportPhotoUploadRequest,
  FieldReportPhotoUploadResult,
} from "@/field-reports/syncFieldReportPhotos";

function bytesToBase64(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary);
}

interface UploadFieldReportPhotoResponse {
  readonly id: string;
  readonly checksum: string;
}

export async function uploadFieldReportPhoto(
  request: FieldReportPhotoUploadRequest,
  session: FieldSessionContext,
): Promise<FieldReportPhotoUploadResult> {
  const body: Record<string, unknown> = {
    id: request.id,
    field_report_id: request.fieldReportId,
    origin_device_id: session.originDeviceId,
    checksum_sha256: request.checksumSha256,
    declared_mime_type: request.mimeType,
    device_uploaded_at: new Date().toISOString(),
    bytes_base64: bytesToBase64(request.bytes),
  };

  // As with the text command: absent when the device knows no node, and the
  // node that receives the upload records itself as the origin (M16.22).
  if (session.originNodeId !== null) {
    body.origin_node_id = session.originNodeId;
  }

  const response = await meridianJson<UploadFieldReportPhotoResponse>(
    "/api/commands/upload-field-report-photo",
    {
      method: "POST",
      body: JSON.stringify(body),
    },
  );

  return {
    id: response.id,
    checksum: response.checksum,
  };
}
