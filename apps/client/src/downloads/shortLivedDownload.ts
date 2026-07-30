// How a surface downloads an authenticated file (M16.12; CLIENT-019,
// CLIENT-020; technical spec 11A.6; data/API 5.7).
//
// A bearer token cannot ride along on a plain browser navigation, and putting
// the credential in the link instead is the thing CLIENT-019 forbids. So the
// download happens in two steps: this client asks the node — with its token —
// for a short-lived URL scoped to one resource, and then the browser navigates
// to that URL, which carries no credential of its own beyond its signature.
//
// The node decides authorization when it issues the URL. A caller that is not
// allowed the file is refused here, before anything opens, so a refusal is an
// error a surface can show rather than a broken tab.
//
// The URL is good for minutes, not hours, and for one resource only. Hold onto
// one no longer than the navigation it was requested for.

import { meridianJson } from "@/api/meridianApi";

/** A URL the node issued, and the moment it stops working. */
export interface ShortLivedDownloadUrl {
  readonly url: string;
  /** ISO-8601, or null when the node did not say. */
  readonly expiresAt: string | null;
}

interface ShortLivedDownloadUrlResponse {
  readonly url?: unknown;
  readonly expires_at?: unknown;
}

/**
 * The endpoints that issue a short-lived URL (data/API 5.7).
 *
 * Named here rather than spelled out at each call site so the surfaces bound in
 * later tasks share one description of where these live.
 */
export const shortLivedDownloadEndpoints = {
  credentialEligibilityExport(eventId: string): string {
    return `/api/events/${encodeURIComponent(eventId)}/exports/credential-eligibility/download-url`;
  },
  incidentPdf(eventId: string, incidentId: string): string {
    return `/api/events/${encodeURIComponent(eventId)}/incidents/${encodeURIComponent(incidentId)}/pdf/download-url`;
  },
  policyDocumentExport(documentId: string, format: string): string {
    return `/api/policy-documents/${encodeURIComponent(documentId)}/export/${encodeURIComponent(format)}/download-url`;
  },
  procedureDocumentExport(documentId: string, format: string): string {
    return `/api/procedure-documents/${encodeURIComponent(documentId)}/export/${encodeURIComponent(format)}/download-url`;
  },
  fieldReportPhoto(attachmentId: string): string {
    return `/api/field-report-photos/${encodeURIComponent(attachmentId)}/download-url`;
  },
  fieldReportPhotoPreview(attachmentId: string): string {
    return `/api/field-report-photos/${encodeURIComponent(attachmentId)}/preview-url`;
  },
} as const;

/**
 * Ask the node for a short-lived URL for one resource.
 *
 * Throws `MeridianApiError` when the node refuses, which is what a caller shows
 * the person: an unauthorized export is a refusal, not an empty file.
 */
export async function requestShortLivedDownloadUrl(
  endpoint: string,
  body: Readonly<Record<string, unknown>> = {},
): Promise<ShortLivedDownloadUrl> {
  const response = await meridianJson<ShortLivedDownloadUrlResponse>(endpoint, {
    method: "POST",
    body: JSON.stringify(body),
  });

  const url = typeof response?.url === "string" ? response.url : "";

  if (url === "") {
    throw new Error("The node issued no download URL for this resource.");
  }

  return {
    url,
    expiresAt:
      typeof response?.expires_at === "string" ? response.expires_at : null,
  };
}

/**
 * Navigate to an issued URL.
 *
 * A link click rather than a fetch, so the browser handles the response as the
 * download it is: the server's own filename is used, and a large export never
 * has to be held in memory to be saved.
 */
export function openShortLivedDownloadUrl(url: string): void {
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.rel = "noopener";
  // No `download` attribute: the node states the filename in its
  // Content-Disposition, and a same-origin `download` here would override it
  // with the last path segment.
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
}

/**
 * The whole pattern in one call: ask for a URL, then navigate to it.
 *
 * Returns the URL that was used so a caller can report when it expires.
 */
export async function downloadThroughShortLivedUrl(
  endpoint: string,
  body: Readonly<Record<string, unknown>> = {},
): Promise<ShortLivedDownloadUrl> {
  const issued = await requestShortLivedDownloadUrl(endpoint, body);

  openShortLivedDownloadUrl(issued.url);

  return issued;
}
