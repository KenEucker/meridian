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
  const response = await meridianJson<UploadFieldReportPhotoResponse>(
    "/api/commands/upload-field-report-photo",
    {
      method: "POST",
      body: JSON.stringify({
        id: request.id,
        field_report_id: request.fieldReportId,
        origin_device_id: session.originDeviceId,
        origin_node_id: session.originNodeId,
        checksum_sha256: request.checksumSha256,
        declared_mime_type: request.mimeType,
        device_uploaded_at: new Date().toISOString(),
        bytes_base64: bytesToBase64(request.bytes),
      }),
    },
  );

  return {
    id: response.id,
    checksum: response.checksum,
  };
}
