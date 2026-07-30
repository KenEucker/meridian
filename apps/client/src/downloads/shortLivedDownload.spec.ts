import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError, configureMeridianApi } from "@/api/meridianApi";
import {
  downloadThroughShortLivedUrl,
  requestShortLivedDownloadUrl,
  shortLivedDownloadEndpoints,
} from "@/downloads/shortLivedDownload";

/*
 * Downloading an authenticated file (M16.12; CLIENT-019, CLIENT-020; technical
 * spec 11A.6; data/API 5.7).
 *
 * No server runs here (CLIENT-024). What is under test is the client half of
 * the pattern: the token asks for a URL, and the navigation that follows is a
 * plain one.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://127.0.0.1:8000",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  configureMeridianApi(null);
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("requesting a short-lived download URL", () => {
  it("asks the node with the client's credential and returns the URL it issued", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse({
        url: "http://127.0.0.1:8000/downloads/events/e1/exports/credential-eligibility?actor=u1&expires=1&signature=abc",
        expires_at: "2026-07-30T18:05:00+00:00",
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      requestShortLivedDownloadUrl(
        shortLivedDownloadEndpoints.credentialEligibilityExport("e1"),
      ),
    ).resolves.toEqual({
      url: "http://127.0.0.1:8000/downloads/events/e1/exports/credential-eligibility?actor=u1&expires=1&signature=abc",
      expiresAt: "2026-07-30T18:05:00+00:00",
    });

    const [path, init] = fetchMock.mock.calls[0] as unknown as [
      string,
      RequestInit,
    ];
    expect(path).toBe(
      "http://127.0.0.1:8000/api/events/e1/exports/credential-eligibility/download-url",
    );
    expect(init.method).toBe("POST");
    // The credential is on the request that asks for the URL, which is the
    // whole point: it is not in the URL that comes back.
    expect(new Headers(init.headers).get("Authorization")).toBe(
      "Bearer device-token",
    );
  });

  it("surfaces the node's refusal instead of opening anything", async () => {
    const openedUrls: string[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
      function click(this: HTMLAnchorElement): void {
        openedUrls.push(this.href);
      },
    );
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse(
          {
            message:
              "You do not have permission to export credential eligibility for this event.",
          },
          403,
        ),
      ),
    );

    // CLIENT-020: authorization is decided when the URL is issued, so an
    // unauthorized export is a refusal the surface can show rather than a tab
    // that opens onto an error page.
    await expect(
      downloadThroughShortLivedUrl(
        shortLivedDownloadEndpoints.credentialEligibilityExport("e1"),
      ),
    ).rejects.toThrow(MeridianApiError);

    expect(openedUrls).toEqual([]);
  });

  it("refuses a response that carries no URL", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => jsonResponse({ expires_at: "2026-07-30T18:05:00Z" })),
    );

    await expect(
      requestShortLivedDownloadUrl(
        shortLivedDownloadEndpoints.incidentPdf("e1", "i1"),
      ),
    ).rejects.toThrow(/no download URL/u);
  });
});

describe("navigating to a short-lived download URL", () => {
  it("navigates to the issued URL and lets the node name the file", async () => {
    const issuedUrl =
      "http://127.0.0.1:8000/downloads/events/e1/incidents/i1/pdf?actor=u1&expires=1&signature=abc";
    let anchor: HTMLAnchorElement | null = null;
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
      function click(this: HTMLAnchorElement): void {
        anchor = this;
      },
    );
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse({ url: issuedUrl, expires_at: "2026-07-30T18:05:00Z" }),
      ),
    );

    await downloadThroughShortLivedUrl(
      shortLivedDownloadEndpoints.incidentPdf("e1", "i1"),
    );

    expect(anchor).not.toBeNull();
    expect(anchor!.href).toBe(issuedUrl);
    // No `download` attribute: the node states the filename in its
    // Content-Disposition, and setting one here would replace it.
    expect(anchor!.hasAttribute("download")).toBe(false);
    // The navigation is over once it starts; nothing is left in the document.
    expect(document.body.contains(anchor!)).toBe(false);
  });
});

describe("the endpoints that issue a URL", () => {
  it("names each resource from data/API 5.7 and escapes its identifiers", () => {
    expect(
      shortLivedDownloadEndpoints.credentialEligibilityExport("e 1"),
    ).toBe("/api/events/e%201/exports/credential-eligibility/download-url");
    expect(shortLivedDownloadEndpoints.incidentPdf("e1", "i1")).toBe(
      "/api/events/e1/incidents/i1/pdf/download-url",
    );
    expect(
      shortLivedDownloadEndpoints.policyDocumentExport("p1", "markdown"),
    ).toBe("/api/policy-documents/p1/export/markdown/download-url");
    expect(
      shortLivedDownloadEndpoints.procedureDocumentExport("p1", "pdf"),
    ).toBe("/api/procedure-documents/p1/export/pdf/download-url");
    expect(shortLivedDownloadEndpoints.fieldReportPhoto("a1")).toBe(
      "/api/field-report-photos/a1/download-url",
    );
    expect(shortLivedDownloadEndpoints.fieldReportPhotoPreview("a1")).toBe(
      "/api/field-report-photos/a1/preview-url",
    );
  });
});
