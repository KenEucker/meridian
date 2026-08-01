// The incident PDF export (INC-015; M11.10, bound to the node in M16.20 through
// the M16.12 download path; CLIENT-019, CLIENT-020; technical spec 11A.6).
//
// This module used to hold two exports. A real event's incident was fetched from
// the export endpoint with the bearer token and saved as a blob; the local IMS
// fixture's incident was rendered into a PDF the browser drew for itself, out of
// data the browser had made up. The second one is gone with the fixture, and so
// is the dependency-free PDF writer it needed: the node owns what an incident
// export contains — its header, its timeline, its branding, and the export audit
// entry that records who took it (INC-015).
//
// What is left is the download pattern the milestone settled on. A bearer token
// cannot ride a browser navigation, so this asks the node for a short-lived URL
// scoped to one incident's PDF and then navigates to it. The node decides the
// print permission when it issues the URL, so a caller who may not print is
// refused before anything opens, and the file arrives with the node's own
// filename rather than one assembled here.

import {
  downloadThroughShortLivedUrl,
  shortLivedDownloadEndpoints,
  type ShortLivedDownloadUrl,
} from "@/downloads/shortLivedDownload";

/**
 * Download one incident's PDF.
 *
 * Returns the issued URL so a surface can report when it stops working. Throws
 * `MeridianApiError` when the node refuses, which is what the surface shows: an
 * unauthorized export is a refusal with a sentence, not an empty file.
 */
export async function downloadIncidentPdf(
  eventId: string,
  incidentId: string,
): Promise<ShortLivedDownloadUrl> {
  return downloadThroughShortLivedUrl(
    shortLivedDownloadEndpoints.incidentPdf(eventId, incidentId),
  );
}
