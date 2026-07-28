import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";

import BrandingLogoField from "@/branding/BrandingLogoField.vue";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

function selectFile(input: HTMLInputElement, file: File): void {
  Object.defineProperty(input, "files", {
    value: [file],
    configurable: true,
  });
}

const baseProps = {
  label: "Compact mark",
  description: "The square mark used in the application header.",
  slot: "compact_mark" as const,
  organizationId: "org-harbor",
  lettermark: "DHC",
};

describe("BrandingLogoField", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("shows the generated lettermark when the slot is empty", () => {
    // BRAND-005: an empty slot is not blank, it is the fallback that renders.
    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: null },
    });

    expect(wrapper.get(".branding-logo__lettermark").text()).toBe("DHC");
    expect(wrapper.find(".branding-logo__image").exists()).toBe(false);
    expect(wrapper.find("[data-action='remove-logo']").exists()).toBe(false);
  });

  it("shows the current logo and a remove control when one is set", () => {
    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: "/branding/assets/mark" },
    });

    expect(wrapper.get(".branding-logo__image").attributes("src")).toBe(
      "/branding/assets/mark",
    );
    expect(wrapper.find("[data-action='remove-logo']").exists()).toBe(true);
  });

  it("accepts only the permitted raster types", () => {
    // BRAND-023, and SVG is deliberately excluded: the asset is served inline
    // and unauthenticated so it renders in email and generated PDFs.
    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: null },
    });

    const accept = wrapper.get("input[type='file']").attributes("accept");

    expect(accept).toBe("image/png,image/webp,image/jpeg");
    expect(accept).not.toContain("svg");
    expect(wrapper.text()).toContain("SVG is not accepted");
  });

  it("uploads the selected file as multipart and reports the new url", async () => {
    const fetchMock = vi.fn(async (_input: unknown, _init?: unknown) =>
      jsonResponse({
        attachment_id: "attachment-1",
        slot: "compact_mark",
        mime_type: "image/png",
        byte_size: 128,
        url: "/branding/assets/attachment-1",
      }, 201),
    );
    vi.stubGlobal("fetch", fetchMock);

    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: null },
    });

    const input = wrapper.get("input[type='file']");
    selectFile(input.element as HTMLInputElement, new File(["x"], "mark.png", {
      type: "image/png",
    }));

    await input.trigger("change");
    await flushPromises();

    const init = fetchMock.mock.calls[0][1] as RequestInit;

    expect(init.body).toBeInstanceOf(FormData);
    // The browser sets the multipart boundary; overriding the content type
    // makes the request unparseable on the server.
    expect(new Headers(init.headers).get("Content-Type")).toBeNull();

    expect(wrapper.emitted("changed")?.[0]).toEqual([
      "/branding/assets/attachment-1",
    ]);
    expect(wrapper.get(".branding-logo__image").attributes("src")).toBe(
      "/branding/assets/attachment-1",
    );
  });

  it("refuses an oversized file before uploading it", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: null },
    });

    const input = wrapper.get("input[type='file']");
    const large = new File(["x"], "huge.png", { type: "image/png" });
    Object.defineProperty(large, "size", { value: 5 * 1024 * 1024 });
    selectFile(input.element as HTMLInputElement, large);

    await input.trigger("change");
    await flushPromises();

    expect(fetchMock).not.toHaveBeenCalled();
    expect(wrapper.get("[role='alert']").text()).toContain("at most 2048 KB");
  });

  it("surfaces a server refusal without changing what is shown", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse(
          { message: "A branding logo must be one of image/png, image/webp, image/jpeg." },
          422,
        ),
      ),
    );

    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: "/branding/assets/mark" },
    });

    const input = wrapper.get("input[type='file']");
    selectFile(input.element as HTMLInputElement, new File(["x"], "mark.svg", {
      type: "image/svg+xml",
    }));

    await input.trigger("change");
    await flushPromises();

    expect(wrapper.get("[role='alert']").text()).toContain("must be one of");
    expect(wrapper.get(".branding-logo__image").attributes("src")).toBe(
      "/branding/assets/mark",
    );
    expect(wrapper.emitted("changed")).toBeUndefined();
  });

  it("removes the logo and falls back to the lettermark", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => jsonResponse({ slot: "compact_mark", attachment_id: null })),
    );

    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: "/branding/assets/mark" },
    });

    await wrapper.get("[data-action='remove-logo']").trigger("click");
    await flushPromises();

    expect(wrapper.emitted("changed")?.[0]).toEqual([null]);
    expect(wrapper.get(".branding-logo__lettermark").text()).toBe("DHC");
  });

  it("disables its controls for a user who may only view", () => {
    const wrapper = mount(BrandingLogoField, {
      props: { ...baseProps, url: "/branding/assets/mark", disabled: true },
    });

    expect(
      wrapper.get("input[type='file']").attributes("disabled"),
    ).toBeDefined();
    expect(
      wrapper.get("[data-action='remove-logo']").attributes("disabled"),
    ).toBeDefined();
  });
});
