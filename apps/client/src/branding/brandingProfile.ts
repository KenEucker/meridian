import { reactive, readonly } from "vue";

import {
  MERIDIAN_DEFAULT_PALETTE,
  departmentTokens,
  lightModeTokens,
  organizationTokens,
  platformTokens,
  type BrandingPalette,
} from "@meridian/ui-tokens/branding";

import { meridianJson } from "@/api/meridianApi";

/**
 * The organization branding a client surface renders with (M15A.4, M15A.8,
 * M15A.12; BRAND-002, BRAND-003, BRAND-006, BRAND-013, BRAND-022).
 *
 * Three things happen here and they are deliberately in one place.
 *
 * 1. **Resolution.** The profile is fetched once and cached in local storage.
 *    A device that has been offline since it was last online still knows whose
 *    system it is (BRAND-022), which matters because branding is the first
 *    thing a user sees and a cold start with no network would otherwise show
 *    Meridian's identity to an organization that has replaced it.
 * 2. **Application.** Tokens are written onto `document.documentElement` and
 *    `data-organization-branding` is set, which is the attribute the token
 *    layer keys on (UI implementation contract 10.3). Writing custom
 *    properties rather than injecting a stylesheet keeps a single place to
 *    undo, which is what `resetToMeridian()` does for the surfaces BRAND-003
 *    protects.
 * 3. **Identity.** Display name, mark, and lettermark are exposed as state the
 *    shell and the document title read.
 *
 * The cache is keyed by organization. A shared workstation that switches
 * organizations must not paint the previous organization's colors while the
 * new profile loads.
 */

export interface BrandingDepartment {
  readonly department_id: string;
  readonly name: string;
  readonly accent: string | null;
  readonly surface: string | null;
  readonly lettermark: string;
  readonly logo_url: string | null;
}

export interface BrandingProfilePayload {
  readonly organization_id: string | null;
  readonly display_name: string;
  readonly is_branded: boolean;
  /**
   * Whether the organization chose a palette, as opposed to merely having a
   * name or a logo. Identity replacement and palette replacement are separate
   * decisions; an organization may make either without the other.
   */
  readonly has_custom_palette: boolean;
  readonly document_attribute: "applied" | "default";
  readonly lettermark: string;
  readonly department_branding_enabled: boolean;
  readonly palette: BrandingPalette;
  readonly tokens: Readonly<Record<string, string>>;
  readonly full_lockup_url: string | null;
  readonly compact_mark_url: string | null;
  readonly departments: readonly BrandingDepartment[];
}

const CACHE_PREFIX = "meridian.branding.";

export const MERIDIAN_PROFILE: BrandingProfilePayload = {
  organization_id: null,
  display_name: "Meridian",
  is_branded: false,
  has_custom_palette: false,
  document_attribute: "default",
  lettermark: "ME",
  department_branding_enabled: true,
  palette: MERIDIAN_DEFAULT_PALETTE,
  tokens: organizationTokens(MERIDIAN_DEFAULT_PALETTE),
  full_lockup_url: null,
  compact_mark_url: null,
  departments: [],
};

interface BrandingState {
  profile: BrandingProfilePayload;
  /** True once a profile has been read from the network in this session. */
  loaded: boolean;
  /** True when the rendered profile came from cache rather than the network. */
  fromCache: boolean;
}

const state = reactive<BrandingState>({
  profile: MERIDIAN_PROFILE,
  loaded: false,
  fromCache: false,
});

export const brandingState = readonly(state);

const STYLE_ELEMENT_ID = "meridian-branding-tokens";

function cacheKey(organizationId: string): string {
  return `${CACHE_PREFIX}${organizationId}`;
}

export function readCachedProfile(
  organizationId: string,
): BrandingProfilePayload | null {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    const raw = window.localStorage.getItem(cacheKey(organizationId));
    return raw ? (JSON.parse(raw) as BrandingProfilePayload) : null;
  } catch {
    // A corrupt cache entry is not worth failing a page load over; the
    // network copy replaces it on the next successful fetch.
    return null;
  }
}

function writeCachedProfile(profile: BrandingProfilePayload): void {
  if (typeof window === "undefined" || !profile.organization_id) {
    return;
  }

  try {
    window.localStorage.setItem(
      cacheKey(profile.organization_id),
      JSON.stringify(profile),
    );
  } catch {
    // Storage being full or blocked costs the offline copy, not this render.
  }
}

function declarations(tokens: Readonly<Record<string, string>>): string {
  return Object.entries(tokens)
    .map(([token, value]) => `  ${token}: ${value};`)
    .join("\n");
}

/**
 * Write a resolved profile onto the document.
 *
 * Applied as a stylesheet rather than as inline custom properties on `<html>`,
 * and that is not a style preference. Inline properties beat every selector,
 * so writing the organization's light-mode neutrals there overrode
 * `[data-theme="dark"]` and painted a white canvas at night. A stylesheet lets
 * the neutrals be scoped out of dark mode.
 *
 * Only the platform colors carry into dark mode. The six neutrals are one
 * light set (BRAND-006), validated against light backgrounds.
 *
 * Nothing is emitted at all unless the organization actually chose a palette.
 * A logo and a display name are identity (BRAND-002); they do not imply that
 * Meridian's default colors were selected on purpose, and treating them as a
 * choice is what made an unstyled organization override the dark theme.
 *
 * Surfaces that must keep Meridian identity (login, magic-link landing, node
 * first-run setup, Orchid, desktop chrome — BRAND-003) simply never call this,
 * and the rules are scoped to `[data-organization-branding="applied"]` so they
 * would not apply even if they did.
 */
export function applyBrandingProfile(profile: BrandingProfilePayload): void {
  state.profile = profile;

  if (typeof document === "undefined") {
    return;
  }

  const root = document.documentElement;
  root.dataset.organizationBranding = profile.document_attribute;

  const existing = document.getElementById(STYLE_ELEMENT_ID);

  if (profile.document_attribute !== "applied" || !profile.has_custom_palette) {
    existing?.remove();
    return;
  }

  const style =
    existing ??
    (() => {
      const element = document.createElement("style");
      element.id = STYLE_ELEMENT_ID;
      document.head.append(element);
      return element;
    })();

  style.textContent = [
    `:root[data-organization-branding="applied"] {`,
    declarations(platformTokens(profile.palette)),
    `}`,
    `:root[data-organization-branding="applied"]:not([data-theme="dark"]) {`,
    declarations(lightModeTokens(profile.palette)),
    `}`,
  ].join("\n");
}

/** Return the document to Meridian's own identity. */
export function resetToMeridian(): void {
  applyBrandingProfile(MERIDIAN_PROFILE);
  state.loaded = false;
  state.fromCache = false;
}

/**
 * Resolve and apply an organization's branding.
 *
 * The cached copy is applied first when there is one, so an organization's
 * identity renders immediately rather than flashing Meridian's and then
 * changing. The network copy replaces it when it arrives; when it does not,
 * the cached copy is what the device keeps rendering (BRAND-022).
 */
export async function loadBrandingProfile(
  organizationId: string,
): Promise<BrandingProfilePayload> {
  const cached = readCachedProfile(organizationId);

  if (cached) {
    state.fromCache = true;
    applyBrandingProfile(cached);
  } else {
    // An unknown organization renders Meridian rather than the previous
    // organization's colors.
    applyBrandingProfile(MERIDIAN_PROFILE);
  }

  try {
    const profile = await meridianJson<BrandingProfilePayload>(
      `/api/organizations/${organizationId}/branding`,
    );

    state.loaded = true;
    state.fromCache = false;
    writeCachedProfile(profile);
    applyBrandingProfile(profile);

    return profile;
  } catch {
    // Offline, or the server is unreachable. The cached profile — or
    // Meridian's own identity when there is none — is already applied.
    return state.profile;
  }
}

/**
 * The department branding tokens for a scoped surface, already filtered by the
 * organization switch (BRAND-013).
 */
export function departmentBrandingTokens(
  departmentId: string,
): Readonly<Record<string, string>> {
  const department = state.profile.departments.find(
    (candidate) => candidate.department_id === departmentId,
  );

  if (!department) {
    return {};
  }

  return departmentTokens(
    { accent: department.accent, surface: department.surface },
    state.profile.department_branding_enabled,
  );
}

export function findDepartmentBranding(
  departmentId: string,
): BrandingDepartment | null {
  return (
    state.profile.departments.find(
      (candidate) => candidate.department_id === departmentId,
    ) ?? null
  );
}

/** Test seam: install a profile without touching the network. */
export function installBrandingProfileForTests(
  profile: BrandingProfilePayload,
): void {
  applyBrandingProfile(profile);
  state.loaded = true;
  state.fromCache = false;
}
