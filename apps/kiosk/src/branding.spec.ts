import { describe, expect, it } from "vitest";

import {
  brandingManifestUrl,
  resolveBrandedOrganizationId,
  resolveWindowIconUrl,
} from "./branding";

/**
 * The desktop window icon carve-out (BRAND-003A).
 *
 * BRAND-003 keeps Meridian's identity on desktop chrome and installers.
 * BRAND-003A carves out the running window and taskbar icon of an application
 * locked to an event, and nothing else — so most of what these assert is the
 * boundary rather than the feature.
 */
describe("desktop window branding", () => {
  it("brands the window when the node is locked to an event", () => {
    expect(
      resolveBrandedOrganizationId({
        node_role: "onsite",
        organization_id: "org-harbor",
        event_id: "event-1",
      }),
    ).toBe("org-harbor");
  });

  it("keeps Meridian's icon when the node names no event", () => {
    // An install that is not running an event is not "locked to an event",
    // and its icon identifies the software rather than a deployment.
    expect(
      resolveBrandedOrganizationId({
        node_role: "central",
        organization_id: "org-harbor",
        event_id: null,
      }),
    ).toBeNull();
  });

  it("keeps Meridian's icon when the node names no organization", () => {
    expect(
      resolveBrandedOrganizationId({
        node_role: "standalone",
        organization_id: null,
        event_id: "event-1",
      }),
    ).toBeNull();
  });

  it("keeps Meridian's icon when node identity is unavailable", () => {
    // An unreachable server must not take the icon down with it.
    expect(resolveBrandedOrganizationId(null)).toBeNull();
  });

  it("treats blank identifiers as absent", () => {
    expect(
      resolveBrandedOrganizationId({ organization_id: "   ", event_id: "event-1" }),
    ).toBeNull();
    expect(
      resolveBrandedOrganizationId({ organization_id: "org-harbor", event_id: "  " }),
    ).toBeNull();
  });

  it("prefers the compact mark over the full lockup", () => {
    // A window icon renders at 32 pixels or less, where a wide lockup is an
    // unreadable smear.
    expect(
      resolveWindowIconUrl({
        is_branded: true,
        assets: [
          { slot: "full_lockup", url: "/branding/assets/lockup" },
          { slot: "compact_mark", url: "/branding/assets/mark" },
        ],
      }),
    ).toBe("/branding/assets/mark");
  });

  it("falls back to the full lockup when there is no compact mark", () => {
    expect(
      resolveWindowIconUrl({
        is_branded: true,
        assets: [{ slot: "full_lockup", url: "/branding/assets/lockup" }],
      }),
    ).toBe("/branding/assets/lockup");
  });

  it("keeps Meridian's icon for an unbranded organization", () => {
    expect(
      resolveWindowIconUrl({
        is_branded: false,
        assets: [{ slot: "compact_mark", url: "/branding/assets/mark" }],
      }),
    ).toBeNull();
  });

  it("keeps Meridian's icon when the organization has no logo asset", () => {
    // A lettermark is a UI fallback, not an icon file; the OS icon stays
    // Meridian's rather than becoming a generated glyph.
    expect(resolveWindowIconUrl({ is_branded: true, assets: [] })).toBeNull();
    expect(resolveWindowIconUrl({ is_branded: true })).toBeNull();
    expect(resolveWindowIconUrl(null)).toBeNull();
  });

  it("prefers the locked event's logo over the organization mark", () => {
    // BRAND-029. This function only runs on an event-locked install, and the
    // people using one were recruited by the event rather than by the company
    // producing it — a producer's mark in the taskbar identifies nothing to
    // someone who has never heard of the producer.
    expect(
      resolveWindowIconUrl({
        is_branded: true,
        locked_event_id: "event-1",
        assets: [
          { slot: "compact_mark", url: "/branding/assets/mark" },
          { slot: "full_lockup", url: "/branding/assets/lockup" },
          { slot: "event_logo", url: "/branding/assets/event" },
        ],
      }),
    ).toBe("/branding/assets/event");
  });

  it("uses the event logo even when the organization is unbranded", () => {
    // Uploading an event logo is a deliberate act; an event running under an
    // otherwise unbranded organization is the case BRAND-028 exists for.
    expect(
      resolveWindowIconUrl({
        is_branded: false,
        locked_event_id: "event-1",
        assets: [{ slot: "event_logo", url: "/branding/assets/event" }],
      }),
    ).toBe("/branding/assets/event");
  });

  it("falls back to the organization mark when the locked event has no logo", () => {
    expect(
      resolveWindowIconUrl({
        is_branded: true,
        locked_event_id: "event-1",
        assets: [{ slot: "compact_mark", url: "/branding/assets/mark" }],
      }),
    ).toBe("/branding/assets/mark");
  });

  it("ignores department and team logos, which are not product identity", () => {
    expect(
      resolveWindowIconUrl({
        is_branded: true,
        assets: [
          { slot: "department_logo:dept-1", url: "/branding/assets/dept" },
          { slot: "team_logo:team-1", url: "/branding/assets/team" },
        ],
      }),
    ).toBeNull();
  });

  it("builds the manifest url against the server origin", () => {
    expect(brandingManifestUrl("http://localhost:8000/", "org-harbor")).toBe(
      "http://localhost:8000/branding/org-harbor/manifest.json",
    );
  });

  it("escapes the organization identifier", () => {
    expect(brandingManifestUrl("http://localhost:8000/", "a/b")).toBe(
      "http://localhost:8000/branding/a%2Fb/manifest.json",
    );
  });
});
