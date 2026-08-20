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

/**
 * A team's mark (BRAND-025). Only teams that have uploaded one are sent; a
 * team with no logo renders a lettermark derived from the name the client
 * already has.
 */
export interface BrandingTeam {
  readonly team_id: string;
  readonly department_id: string;
  readonly name: string;
  readonly lettermark: string;
  readonly logo_url: string | null;
}

/**
 * The event this install is locked to (BRAND-028), or null when it is not
 * locked to one.
 *
 * Present even when the event has no mark: "locked to an event with no logo"
 * and "not locked to an event" are different states, and only the first has an
 * event name to show.
 */
export interface BrandingEvent {
  readonly event_id: string;
  readonly name: string;
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
  readonly teams: readonly BrandingTeam[];
  readonly event: BrandingEvent | null;
}

const CACHE_PREFIX = "meridian.branding.";
const DOWNLOADED_AT_PREFIX = "meridian.branding-downloaded-at.";

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
  teams: [],
  event: null,
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

    if (!raw) {
      return null;
    }

    // A cache entry written before a field existed is missing it, and a device
    // that has been offline across an upgrade reads exactly that. The
    // collections are defaulted rather than the entry discarded: an old cache
    // still carries the organization's identity, which is the thing BRAND-022
    // exists to keep.
    const parsed = JSON.parse(raw) as BrandingProfilePayload;

    return {
      ...parsed,
      departments: parsed.departments ?? [],
      teams: parsed.teams ?? [],
      event: parsed.event ?? null,
    };
  } catch {
    // A corrupt cache entry is not worth failing a page load over; the
    // network copy replaces it on the next successful fetch.
    return null;
  }
}

/*
 * When this device last downloaded an organization's branding assets, for the
 * offline download status (CLIENT-025; technical spec 9.7).
 *
 * A stamp beside the cached profile rather than a field inside it, so an entry
 * written by an earlier build stays readable: the profile cache's shape is the
 * node's payload, and the moment this device stored it is this device's own
 * fact.
 */

function writeBrandingDownloadedAt(organizationId: string, at: string): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(`${DOWNLOADED_AT_PREFIX}${organizationId}`, at);
  } catch {
    // Best effort, like the profile cache itself: losing the stamp costs the
    // download-status row its timestamp, not the device its branding.
  }
}

/** Device time of the last successful branding download, or null. */
export function brandingAssetsDownloadedAt(
  organizationId: string,
): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    return window.localStorage.getItem(
      `${DOWNLOADED_AT_PREFIX}${organizationId}`,
    );
  } catch {
    return null;
  }
}

/**
 * Whether this device holds the organization's branding assets — applied this
 * session or waiting in the cache from an earlier one (BRAND-022).
 */
export function brandingAssetsHeld(organizationId: string): boolean {
  return (
    state.profile.organization_id === organizationId ||
    readCachedProfile(organizationId) !== null
  );
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

    if (profile.organization_id !== null) {
      writeBrandingDownloadedAt(
        profile.organization_id,
        new Date().toISOString(),
      );
    }

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

/**
 * The mark the product's own chrome carries — the application header, the
 * favicon, and the desktop window icon (BRAND-005, BRAND-028).
 *
 * The locked event's mark comes first. On an install locked to an event, most
 * of the people using it were recruited by the event rather than by the company
 * producing it, and a producer's mark identifies nothing to someone who has
 * never heard of the producer. Where an organizer has given the event a mark,
 * that is the one those staff can recognise.
 *
 * It wins even for an organization with no branding profile, because uploading
 * an event logo is itself a deliberate act; there is no reading of it under
 * which an organizer wanted it stored and not shown.
 *
 * After that it is the organization's, compact mark before full lockup — an
 * icon renders small, where a wide lockup becomes a smear. Null means fall
 * through to the generated lettermark.
 *
 * Mirrors `BrandingProfile::chromeMarkAttachmentId()`. The client resolves it
 * locally rather than reading a resolved URL from the payload because it has to
 * answer the same question from a cached profile with no network.
 */
export function chromeMarkUrl(
  profile: BrandingProfilePayload = state.profile,
): string | null {
  if (showsEventIdentity(profile)) {
    return profile.event.logo_url;
  }

  if (!profile.is_branded) {
    return null;
  }

  return profile.compact_mark_url ?? profile.full_lockup_url ?? null;
}

/**
 * Whether this install presents the locked event's identity rather than the
 * organization's (BRAND-029).
 *
 * Keyed on the event having a logo, not merely on the install being
 * event-locked. An event that has set nothing has not asked to be presented as
 * the product, and a locked install with no event mark should read exactly as
 * it did before the event existed.
 */
export function showsEventIdentity(
  profile: BrandingProfilePayload = state.profile,
): profile is BrandingProfilePayload & {
  event: BrandingEvent & { logo_url: string };
} {
  return Boolean(profile.event?.logo_url);
}

/**
 * The name shown beside the chrome mark (BRAND-029).
 *
 * The mark and the name are one identity and move together. A header carrying
 * the event's logo next to the producing company's name — or next to
 * "Meridian" — is the same failure the event mark exists to fix: it asks a
 * staff member to recognise something they have no reason to know, in the one
 * place they look to confirm they are in the right app.
 *
 * Mirrors `BrandingProfile::chromeIdentityName()`.
 */
export function chromeIdentityName(
  profile: BrandingProfilePayload = state.profile,
): string {
  if (showsEventIdentity(profile)) {
    return profile.event.name;
  }

  return profile.is_branded ? profile.display_name : "Meridian";
}

/**
 * A team's mark, or null when the team has not uploaded one (BRAND-025).
 *
 * A null answer is the common case and not an error: only teams with a stored
 * logo appear in the payload, and every consumer falls back to a lettermark
 * from the team name it is already rendering.
 */
export function findTeamBranding(teamId: string): BrandingTeam | null {
  return (
    state.profile.teams?.find((candidate) => candidate.team_id === teamId) ??
    null
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
