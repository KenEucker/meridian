import { afterEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError, configureMeridianApi } from "@/api/meridianApi";
import {
  downloadIncidentPdf,
  downloadIncidentPdfForSession,
} from "@/ims/downloadIncidentPdf";
import {
  LOCAL_IMS_EVENT_ID,
  findIncidentForSession,
  installIncidentSession,
  type IncidentSessionContext,
} from "@/ims/incidentReadModel";

const IC_LEAD_SESSION: IncidentSessionContext = {
  eventId: LOCAL_IMS_EVENT_ID,
  eventLabel: "Local Field Event",
  organizationLabel: "Local Field Organization",
  icDepartmentLabel: "Rangers",
  role: "ic_lead",
  roleLabel: "Incident Command Lead",
};

describe("downloadIncidentPdf", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    configureMeridianApi(null);
  });

  it("downloads an authorized PDF attachment from the server API", async () => {
    configureMeridianApi({
      baseUrl: "http://meridian.test",
      bearerToken: "local-token",
    });

    const click = vi.fn();
    const remove = vi.fn();
    vi.spyOn(document.body, "appendChild").mockImplementation((node) => node);
    vi.spyOn(document, "createElement").mockReturnValue({
      click,
      remove,
      rel: "",
      href: "",
      download: "",
    } as unknown as HTMLAnchorElement);
    vi.spyOn(URL, "createObjectURL").mockReturnValue("blob:incident-pdf");
    vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => undefined);

    const fetchMock = vi.fn().mockResolvedValue(
      new Response("%PDF-1.4 sample", {
        status: 200,
        headers: {
          "Content-Type": "application/pdf",
          "Content-Disposition":
            'attachment; filename="incident-inc-2027-000042-medical.pdf"',
        },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await downloadIncidentPdf(
      "22222222-2222-4222-8222-222222222222",
      "33333333-3333-4333-8333-333333333333",
    );

    expect(fetchMock).toHaveBeenCalledWith(
      "http://meridian.test/api/events/22222222-2222-4222-8222-222222222222/incidents/33333333-3333-4333-8333-333333333333/pdf",
      expect.objectContaining({
        headers: expect.any(Headers),
      }),
    );
    const headers = fetchMock.mock.calls[0]?.[1]?.headers as Headers;
    expect(headers.get("Accept")).toBe("application/pdf");
    expect(headers.get("Authorization")).toBe("Bearer local-token");
    expect(click).toHaveBeenCalledOnce();
  });

  it("surfaces server authorization failures", async () => {
    configureMeridianApi({
      baseUrl: "http://meridian.test",
      bearerToken: null,
    });

    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            message:
              "Only Incident Command leads for this event may print incidents to PDF.",
          }),
          {
            status: 403,
            headers: { "Content-Type": "application/json" },
          },
        ),
      ),
    );

    await expect(
      downloadIncidentPdf("event-1", "incident-1"),
    ).rejects.toBeInstanceOf(MeridianApiError);
  });

  it("builds a local fixture PDF without calling the server", async () => {
    installIncidentSession(IC_LEAD_SESSION);
    const incident = findIncidentForSession(
      IC_LEAD_SESSION,
      "incident-gate-medical",
    );
    expect(incident).not.toBeNull();

    const click = vi.fn();
    const remove = vi.fn();
    vi.spyOn(document.body, "appendChild").mockImplementation((node) => node);
    const createElement = vi.spyOn(document, "createElement").mockReturnValue({
      click,
      remove,
      rel: "",
      href: "",
      download: "",
    } as unknown as HTMLAnchorElement);
    const createObjectURL = vi
      .spyOn(URL, "createObjectURL")
      .mockReturnValue("blob:local-incident-pdf");
    vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => undefined);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    await downloadIncidentPdfForSession(IC_LEAD_SESSION, incident!);

    expect(fetchMock).not.toHaveBeenCalled();
    expect(createObjectURL).toHaveBeenCalledOnce();
    const blob = createObjectURL.mock.calls[0]?.[0] as Blob;
    expect(blob).toBeInstanceOf(Blob);
    expect(blob.type).toBe("application/pdf");
    expect(createElement).toHaveBeenCalledWith("a");
    expect(click).toHaveBeenCalledOnce();
    expect(remove).toHaveBeenCalledOnce();
  });
});
