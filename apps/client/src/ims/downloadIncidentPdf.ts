import { MeridianApiError, meridianFetch } from "@/api/meridianApi";
import {
  LOCAL_IMS_EVENT_ID,
  canPrintIncidentPdf,
  statusLabel,
  type ImsIncident,
  type IncidentSessionContext,
  type IncidentTimelineEntry,
} from "@/ims/incidentReadModel";
import { renderSimplePdf } from "@/ims/simplePdf";

/**
 * Downloads an incident PDF for an IC lead (INC-015; M11.10).
 *
 * Real event incidents use the server export API. The local IMS development
 * fixture generates a matching dependency-free PDF from fixture data so Print
 * PDF is exercisable without seeded API incidents.
 */
export async function downloadIncidentPdfForSession(
  session: IncidentSessionContext,
  incident: ImsIncident,
): Promise<void> {
  if (!canPrintIncidentPdf(session)) {
    throw new MeridianApiError(
      "Only Incident Command leads for this event may print incidents to PDF.",
      403,
    );
  }

  if (session.eventId === LOCAL_IMS_EVENT_ID) {
    downloadBlob(
      renderSimplePdf(localIncidentPdfLines(incident, session)),
      localFilename(incident),
    );
    return;
  }

  await downloadIncidentPdfFromApi(session.eventId, incident.id);
}

/** @deprecated Prefer downloadIncidentPdfForSession for product surfaces. */
export async function downloadIncidentPdf(
  eventId: string,
  incidentId: string,
): Promise<void> {
  await downloadIncidentPdfFromApi(eventId, incidentId);
}

async function downloadIncidentPdfFromApi(
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
        : typeof body === "string" && body.trim() !== ""
          ? body.trim()
          : `Incident PDF export failed (${response.status}).`;

    throw new MeridianApiError(message, response.status, body);
  }

  const blob = await response.blob();
  const disposition = response.headers.get("Content-Disposition");
  const filename = filenameFromDisposition(disposition) ?? "incident.pdf";
  downloadBlob(blob, filename);
}

function localIncidentPdfLines(
  incident: ImsIncident,
  session: IncidentSessionContext,
): string[] {
  const exportedAt = new Date().toISOString();
  const lines = [
    "Incident PDF Export",
    `IMS number: ${incident.incidentNumber}`,
    `Title: ${incident.title || "Untitled incident"}`,
    `State: ${statusLabel(incident.status)}`,
    `Priority: ${incident.priorityLabel}`,
    `Incident types: ${
      incident.incidentTypeNames.length > 0
        ? incident.incidentTypeNames.join(", ")
        : "Types not set"
    }`,
    `Responders: ${
      incident.responders.length > 0
        ? incident.responders.map((responder) => responder.displayName).join(", ")
        : "Responders not set"
    }`,
    `Started at: ${incident.startedAt}`,
    `Location: ${incident.locationName ?? "Location not set"}`,
  ];

  if (incident.locationAddress) {
    lines.push(`Location address: ${incident.locationAddress}`);
  }

  if (incident.locationDetails) {
    lines.push(`Location details: ${incident.locationDetails}`);
  }

  lines.push(
    `Created by: ${incident.createdByName ?? "Creator unavailable"}`,
    `Created at: ${incident.createdAt}`,
    `Updated at: ${incident.updatedAt}`,
    `Exported at: ${exportedAt}`,
    `Exported by: ${session.roleLabel}`,
    "",
    "Linked incidents:",
    ...(incident.linkedIncidents.length > 0
      ? incident.linkedIncidents.map(
          (linked) =>
            `- ${linked.incidentNumber} ${linked.title || "Untitled incident"} (${statusLabel(linked.status)})`,
        )
      : ["- None"]),
    "",
    "Attached Field Reports:",
    ...(incident.attachedFieldReports.length > 0
      ? incident.attachedFieldReports.map(
          (report) =>
            `- ${report.displayNumber} ${report.title} (Author: ${report.authorName})`,
        )
      : ["- None"]),
    "",
    "Timeline:",
  );

  if (incident.timelineEntries.length === 0) {
    lines.push("- No timeline entries");
    return lines;
  }

  for (const entry of incident.timelineEntries) {
    lines.push(
      `[${entry.createdAt}] ${entry.actorName ?? "Unknown actor"}`,
      timelineBody(entry),
    );
    if (entry.strickenAt) {
      lines.push(
        `Stricken: ${entry.strickenReason ?? "Removed from incident."}`,
      );
    }
    lines.push("");
  }

  if (lines.at(-1) === "") {
    lines.pop();
  }

  return lines;
}

function timelineBody(entry: IncidentTimelineEntry): string {
  if (entry.body) {
    return entry.body;
  }

  if (entry.entryType === "incident_opened") {
    return "Incident opened.";
  }

  return entry.entryType.replaceAll("_", " ");
}

function localFilename(incident: ImsIncident): string {
  const number = slug(incident.incidentNumber) || "incident";
  const title = slug(incident.title) || "untitled";
  return `incident-${number}-${title}.pdf`;
}

function slug(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/gu, "-")
    .replace(/^-+|-+$/gu, "");
}

function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  return bytes.buffer.slice(
    bytes.byteOffset,
    bytes.byteOffset + bytes.byteLength,
  ) as ArrayBuffer;
}

function downloadBlob(contents: Blob | Uint8Array, filename: string): void {
  const blob =
    contents instanceof Blob
      ? contents
      : new Blob([toArrayBuffer(contents)], { type: "application/pdf" });
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
