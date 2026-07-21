import { MeridianApiError, meridianFetch } from "@/api/meridianApi";

/**
 * Downloads a server-generated incident PDF for an IC lead (INC-015; M11.10).
 *
 * PDF generation is online-only. Authorization is enforced by the server
 * (`incidents.print` / ic_lead).
 */
export async function downloadIncidentPdf(
  eventId: string,
  incidentId: string,
): Promise<void> {
  const response = await meridianFetch(
    `/api/events/${encodeURIComponent(eventId)}/incidents/${encodeURIComponent(incidentId)}/pdf`,
    {
      headers: {
        Accept: "application/pdf",
      },
    },
  );

  if (!response.ok) {
    let body: unknown = null;
    const text = await response.text();

    if (text !== "") {
      try {
        body = JSON.parse(text) as unknown;
      } catch {
        body = text;
      }
    }

    const message =
      typeof body === "object" &&
      body !== null &&
      "message" in body &&
      typeof (body as { message: unknown }).message === "string"
        ? (body as { message: string }).message
        : `Incident PDF export failed (${response.status}).`;

    throw new MeridianApiError(message, response.status, body);
  }

  const blob = await response.blob();
  const disposition = response.headers.get("Content-Disposition");
  const filename = filenameFromDisposition(disposition) ?? "incident.pdf";
  const objectUrl = URL.createObjectURL(blob);

  try {
    const anchor = document.createElement("a");
    anchor.href = objectUrl;
    anchor.download = filename;
    anchor.rel = "noopener";
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  } finally {
    URL.revokeObjectURL(objectUrl);
  }
}

function filenameFromDisposition(disposition: string | null): string | null {
  if (!disposition) {
    return null;
  }

  const utfMatch = /filename\*=UTF-8''([^;]+)/iu.exec(disposition);
  if (utfMatch?.[1]) {
    try {
      return decodeURIComponent(utfMatch[1].replaceAll('"', ""));
    } catch {
      return utfMatch[1].replaceAll('"', "");
    }
  }

  const plainMatch = /filename="?([^";]+)"?/iu.exec(disposition);
  return plainMatch?.[1] ?? null;
}
