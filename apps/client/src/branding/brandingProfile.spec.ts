import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  MERIDIAN_PROFILE,
  applyBrandingProfile,
  brandingState,
  chromeIdentityName,
  chromeMarkUrl,
  departmentBrandingTokens,
  findDepartmentBranding,
  findTeamBranding,
  loadBrandingProfile,
  readCachedProfile,
  resetToMeridian,
  type BrandingProfilePayload,
} from "@/branding/brandingProfile";

const harborProfile: BrandingProfilePayload = {
  organization_id: "org-harbor",
  display_name: "Deep Harbor Collective",
  is_branded: true,
  has_custom_palette: true,
  document_attribute: "applied",
  lettermark: "DHC",
  department_branding_enabled: true,
  palette: {
    primary: "#123a5c",
    secondary: "#1f5f4b",
    tertiary: "#6b4f8a",
    accent: "#8c2f39",
    canvas: "#eef2f6",
    surface: "#ffffff",
    foreground: "#101418",
    muted_foreground: "#565f68",
    border: "#7c858d",
    focus: "#1b4f8f",
  },
  tokens: {
    "--m-platform-primary": "#123a5c",
    "--m-surface-app": "#eef2f6",
    "--m-text-primary": "#101418",
  },
  full_lockup_url: "/branding/assets/lockup",
  compact_mark_url: "/branding/assets/mark",
  departments: [
    {
      department_id: "dept-rangers",
      name: "Rangers",
      accent: "#1f5f4b",
      surface: "#eef6f2",
      lettermark: "RA",
      logo_url: null,
    },
  ],
  teams: [
    {
      team_id: "team-dirt",
      department_id: "dept-rangers",
      name: "Dirt",
      lettermark: "DI",
      logo_url: "/branding/assets/team-dirt",
    },
  ],
  event: null,
};

describe("branding profile", () => {
  beforeEach(() => {
    window.localStorage.clear();
    resetToMeridian();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    resetToMeridian();
  });

  it("starts on Meridian's own identity", () => {
    expect(brandingState.profile.display_name).toBe("Meridian");
    expect(brandingState.profile.is_branded).toBe(false);
    expect(document.documentElement.dataset.organizationBranding).toBe(
      "default",
    );
  });

  function brandingCss(): string {
    return document.getElementById("meridian-branding-tokens")?.textContent ?? "";
  }

  it("writes the organization tokens and the document attribute when applied", () => {
    applyBrandingProfile(harborProfile);

    expect(document.documentElement.dataset.organizationBranding).toBe(
      "applied",
    );
    expect(brandingCss()).toContain("--m-platform-primary: #123a5c;");
    expect(brandingCss()).toContain("--m-surface-app: #eef2f6;");
  });

  it("applies branding as a stylesheet rather than inline on the root element", () => {
    // Inline custom properties beat every selector, including
    // `[data-theme="dark"]`, which is what painted a white canvas at night.
    applyBrandingProfile(harborProfile);

    expect(
      document.documentElement.style.getPropertyValue("--m-surface-app"),
    ).toBe("");
    expect(brandingCss()).not.toBe("");
  });

  it("keeps the organization's light neutrals out of dark mode", () => {
    applyBrandingProfile(harborProfile);

    const css = brandingCss();

    // Platform colors carry into both modes; the six neutrals are one light
    // set and are scoped away from dark mode (BRAND-006).
    const platformRule = css.slice(
      css.indexOf(':root[data-organization-branding="applied"] {'),
      css.indexOf(':root[data-organization-branding="applied"]:not'),
    );

    expect(platformRule).toContain("--m-platform-primary");
    expect(platformRule).not.toContain("--m-surface-app");
    expect(css).toContain(':not([data-theme="dark"])');
  });

  it("removes the previous organization's tokens when switching", () => {
    // A shared workstation must not keep painting the last organization's
    // colors after it switches.
    applyBrandingProfile(harborProfile);
    applyBrandingProfile(MERIDIAN_PROFILE);

    expect(document.documentElement.dataset.organizationBranding).toBe(
      "default",
    );
    expect(document.getElementById("meridian-branding-tokens")).toBeNull();
  });

  it("writes no tokens for an unbranded organization", () => {
    // BRAND-003 keeps Meridian identity on protected surfaces by never
    // marking them applied; the token layer is scoped to that attribute.
    applyBrandingProfile({
      ...harborProfile,
      is_branded: false,
      document_attribute: "default",
    });

    expect(document.getElementById("meridian-branding-tokens")).toBeNull();
  });

  it("writes no tokens when the organization never chose a palette", () => {
    // A display name and a logo are identity (BRAND-002). They do not mean
    // Meridian's default colors were picked on purpose, and treating them as
    // a choice let an unstyled organization override the dark theme.
    applyBrandingProfile({ ...harborProfile, has_custom_palette: false });

    expect(document.documentElement.dataset.organizationBranding).toBe(
      "applied",
    );
    expect(document.getElementById("meridian-branding-tokens")).toBeNull();
  });

  it("caches the profile so an offline device still knows whose system it is", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response(JSON.stringify(harborProfile), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })),
    );

    await loadBrandingProfile("org-harbor");

    expect(readCachedProfile("org-harbor")?.display_name).toBe(
      "Deep Harbor Collective",
    );
  });

  it("renders the cached profile when the network is unavailable", async () => {
    window.localStorage.setItem(
      "meridian.branding.org-harbor",
      JSON.stringify(harborProfile),
    );

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const profile = await loadBrandingProfile("org-harbor");

    expect(profile.display_name).toBe("Deep Harbor Collective");
    expect(brandingState.fromCache).toBe(true);
    expect(document.documentElement.dataset.organizationBranding).toBe(
      "applied",
    );
  });

  it("falls back to Meridian rather than the previous organization when nothing is cached", async () => {
    applyBrandingProfile(harborProfile);

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const profile = await loadBrandingProfile("org-unknown");

    expect(profile.display_name).toBe("Meridian");
    expect(document.documentElement.dataset.organizationBranding).toBe(
      "default",
    );
  });

  it("survives a corrupt cache entry", async () => {
    window.localStorage.setItem("meridian.branding.org-harbor", "{not json");

    expect(readCachedProfile("org-harbor")).toBeNull();
  });

  it("exposes department tokens filtered by the organization switch", () => {
    applyBrandingProfile(harborProfile);

    expect(departmentBrandingTokens("dept-rangers")).toEqual({
      "--m-department-accent": "#1f5f4b",
      "--m-department-surface": "#eef6f2",
    });

    applyBrandingProfile({
      ...harborProfile,
      department_branding_enabled: false,
    });

    expect(departmentBrandingTokens("dept-rangers")).toEqual({});
  });

  it("returns nothing for a department with no branding profile", () => {
    applyBrandingProfile(harborProfile);

    expect(departmentBrandingTokens("dept-gate")).toEqual({});
    expect(findDepartmentBranding("dept-gate")).toBeNull();
    expect(findDepartmentBranding("dept-rangers")?.lettermark).toBe("RA");
  });

  it("resolves a team's mark and reports nothing for a team without one", () => {
    // Only teams that have uploaded a logo are in the payload; a null answer
    // is the common case, and every consumer falls back to a lettermark.
    applyBrandingProfile(harborProfile);

    expect(findTeamBranding("team-dirt")?.logo_url).toBe(
      "/branding/assets/team-dirt",
    );
    expect(findTeamBranding("team-dirt")?.department_id).toBe("dept-rangers");
    expect(findTeamBranding("team-greeters")).toBeNull();
  });

  it("prefers the locked event's mark for product chrome", () => {
    // BRAND-029: on an event-locked install the event's mark is the one its
    // staff can recognise, so it replaces the organization's in the header,
    // the tab icon, and the window icon.
    const eventProfile: BrandingProfilePayload = {
      ...harborProfile,
      event: {
        event_id: "event-1",
        name: "Desert Bloom",
        lettermark: "DB",
        logo_url: "/branding/assets/event",
      },
    };

    expect(chromeMarkUrl(eventProfile)).toBe("/branding/assets/event");

    // BRAND-030: the name comes with it. A surface never shows one party's
    // mark beside another party's name.
    expect(chromeIdentityName(eventProfile)).toBe("Desert Bloom");

    // Uploading an event mark is a deliberate act, so it shows even for an
    // organization that never replaced Meridian's identity (BRAND-028).
    expect(
      chromeMarkUrl({ ...eventProfile, is_branded: false }),
    ).toBe("/branding/assets/event");
    expect(
      chromeIdentityName({ ...eventProfile, is_branded: false }),
    ).toBe("Desert Bloom");
  });

  it("keys the event identity on the logo, not on being event-locked", () => {
    // An event that has set nothing has not asked to be presented as the
    // product; the install reads as it did before the event existed.
    const noLogo: BrandingProfilePayload = {
      ...harborProfile,
      event: {
        event_id: "event-1",
        name: "Desert Bloom",
        lettermark: "DB",
        logo_url: null,
      },
    };

    expect(chromeIdentityName(noLogo)).toBe("Deep Harbor Collective");
    expect(chromeMarkUrl(noLogo)).toBe(harborProfile.compact_mark_url);

    expect(
      chromeIdentityName({ ...noLogo, is_branded: false }),
    ).toBe("Meridian");
  });

  it("falls back to the organization mark, compact before lockup", () => {
    expect(chromeMarkUrl(harborProfile)).toBe(harborProfile.compact_mark_url);

    expect(
      chromeMarkUrl({ ...harborProfile, compact_mark_url: null }),
    ).toBe(harborProfile.full_lockup_url);

    // Locked to an event that has no mark of its own: still the organization's.
    expect(
      chromeMarkUrl({
        ...harborProfile,
        event: {
          event_id: "event-1",
          name: "Desert Bloom",
          lettermark: "DB",
          logo_url: null,
        },
      }),
    ).toBe(harborProfile.compact_mark_url);

    // Nothing to show: the caller renders a lettermark or Meridian's own mark.
    expect(
      chromeMarkUrl({
        ...harborProfile,
        is_branded: false,
        compact_mark_url: null,
        full_lockup_url: null,
      }),
    ).toBeNull();
  });

  it("reads a cache entry written before teams were in the payload", () => {
    // A device offline across an upgrade reads exactly this. The entry still
    // carries the organization's identity, which is what BRAND-022 is for, so
    // the missing collection is defaulted rather than the entry discarded.
    const { teams: _teams, ...withoutTeams } = harborProfile;
    window.localStorage.setItem(
      "meridian.branding.org-harbor",
      JSON.stringify(withoutTeams),
    );

    const cached = readCachedProfile("org-harbor");

    expect(cached?.display_name).toBe("Deep Harbor Collective");
    expect(cached?.teams).toEqual([]);

    applyBrandingProfile(cached!);
    expect(findTeamBranding("team-dirt")).toBeNull();
  });
});
