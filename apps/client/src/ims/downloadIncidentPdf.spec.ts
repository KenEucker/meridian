import { afterEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError, configureMeridianApi } from "@/api/meridianApi";
import { downloadIncidentPdf } from "@/ims/downloadIncidentPdf";

describe("downloadIncidentPdf", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    configureMeridianApi(null);
  });

  it("downloads an authorized PDF attachment", async () => {
    configureMeridianApi({
      baseUrl: "http://meridian.test",
      bearerToken: "local-token",
    });

    const click = vi.fn();
    const remove = vi.fn();
    const appendChild = vi
      .spyOn(document.body, "appendChild")
      .mockImplementation((node) => node);
    const createElement = vi
      .spyOn(document, "createElement")
      .mockReturnValue({
        click,
        remove,
        rel: "",
        href: "",
        download: "",
      } as unknown as HTMLAnchorElement);
    const createObjectURL = vi
      .spyOn(URL, "createObjectURL")
      .mockReturnValue("blob:incident-pdf");
    const revokeObjectURL = vi
      .spyOn(URL, "revokeObjectURL")
      .mockImplementation(() => undefined);

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
      "11111111-1111-4111-8111-111111111111",
      "incident-gate-medical",
    );

    expect(fetchMock).toHaveBeenCalledWith(
      "http://meridian.test/api/events/11111111-1111-4111-8111-111111111111/incidents/incident-gate-medical/pdf",
      expect.objectContaining({
        headers: expect.any(Headers),
      }),
    );
    const headers = fetchMock.mock.calls[0]?.[1]?.headers as Headers;
    expect(headers.get("Accept")).toBe("application/pdf");
    expect(headers.get("Authorization")).toBe("Bearer local-token");
    expect(createObjectURL).toHaveBeenCalledOnce();
    expect(createElement).toHaveBeenCalledWith("a");
    expect(click).toHaveBeenCalledOnce();
    expect(appendChild).toHaveBeenCalledOnce();
    expect(remove).toHaveBeenCalledOnce();
    expect(revokeObjectURL).toHaveBeenCalledWith("blob:incident-pdf");
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
});
