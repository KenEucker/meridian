/**
 * The browser tab icon (BRAND-002, BRAND-028).
 *
 * The favicon is the fifth thing a branding profile replaces, and the one a
 * user looks at without meaning to: a staff member with six tabs open finds the
 * app by its icon, not by reading titles. On an install locked to an event that
 * icon should be the event's, for the same reason the header mark is — the
 * people working the event know the event, and may know nothing about the
 * company producing it.
 *
 * Applied at runtime rather than baked into `index.html`, because the branding
 * profile resolves after the document is parsed and the same built client is
 * served to every organization.
 *
 * Restoring is explicit rather than remembered. The element's original `href`
 * is not read back, because by the time anything wants Meridian's icon again
 * the element may already be carrying an organization's — the constant below is
 * the one true answer, and the surfaces BRAND-003 protects are served by Blade
 * and never run this at all.
 */

/** Meridian's own icon, as shipped in the client's `public/` directory. */
export const MERIDIAN_FAVICON_URL = "/favicon.ico";

const LINK_ID = "meridian-favicon";

function faviconElement(): HTMLLinkElement | null {
  if (typeof document === "undefined") {
    return null;
  }

  const existing =
    document.getElementById(LINK_ID) ??
    document.querySelector('link[rel~="icon"]');

  if (existing instanceof HTMLLinkElement) {
    existing.id = LINK_ID;
    return existing;
  }

  const link = document.createElement("link");
  link.id = LINK_ID;
  link.rel = "icon";
  document.head.append(link);

  return link;
}

/**
 * Point the tab icon at `url`, or back at Meridian's when `url` is null.
 *
 * Returns the applied href so a caller can assert on it without reaching into
 * the document.
 */
export function applyFavicon(url: string | null): string {
  const href = url ?? MERIDIAN_FAVICON_URL;
  const link = faviconElement();

  if (link && link.getAttribute("href") !== href) {
    link.setAttribute("href", href);

    // A branding asset is a PNG, WebP, or JPEG (BRAND-023) while Meridian's own
    // is an .ico, and a stale `type` stops some browsers re-reading the file.
    if (url === null) {
      link.removeAttribute("type");
      link.setAttribute("sizes", "any");
    } else {
      link.removeAttribute("sizes");
      link.removeAttribute("type");
    }
  }

  return href;
}
