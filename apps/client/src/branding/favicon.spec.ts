import { afterEach, describe, expect, it } from "vitest";

import { applyFavicon, MERIDIAN_FAVICON_URL } from "@/branding/favicon";

/**
 * The tab icon (BRAND-002, BRAND-029).
 *
 * What is worth pinning is the round trip: an organization's or an event's mark
 * goes on, and Meridian's comes back when there is nothing to replace it with.
 * A one-way applier is the easy mistake, and it leaves a shared workstation
 * showing the last organization's icon after it switches.
 */
function currentHref(): string | null {
  return document
    .querySelector('link[rel~="icon"]')
    ?.getAttribute("href") ?? null;
}

afterEach(() => {
  document.getElementById("meridian-favicon")?.remove();
});

describe("favicon", () => {
  it("creates the link element when the document has none", () => {
    expect(document.querySelector('link[rel~="icon"]')).toBeNull();

    applyFavicon("/branding/assets/event");

    expect(currentHref()).toBe("/branding/assets/event");
  });

  it("adopts an existing icon link rather than adding a second", () => {
    const existing = document.createElement("link");
    existing.rel = "icon";
    existing.href = MERIDIAN_FAVICON_URL;
    document.head.append(existing);

    applyFavicon("/branding/assets/event");

    expect(document.querySelectorAll('link[rel~="icon"]')).toHaveLength(1);
    expect(currentHref()).toBe("/branding/assets/event");

    existing.remove();
  });

  it("returns to Meridian's own icon when there is no branded mark", () => {
    applyFavicon("/branding/assets/event");
    expect(currentHref()).toBe("/branding/assets/event");

    applyFavicon(null);

    expect(currentHref()).toBe(MERIDIAN_FAVICON_URL);
    expect(applyFavicon(null)).toBe(MERIDIAN_FAVICON_URL);
  });

  it("drops the sizes hint for a raster branding asset and restores it", () => {
    // Meridian's own icon is an .ico declaring `sizes="any"`; a branding asset
    // is a single PNG, WebP, or JPEG (BRAND-023) and the stale hint makes some
    // browsers keep the previous file.
    applyFavicon(null);
    const link = document.querySelector('link[rel~="icon"]')!;
    expect(link.getAttribute("sizes")).toBe("any");

    applyFavicon("/branding/assets/event");
    expect(link.getAttribute("sizes")).toBeNull();

    applyFavicon(null);
    expect(link.getAttribute("sizes")).toBe("any");
  });
});
