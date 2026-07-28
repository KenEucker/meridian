import { organizationTokens, type BrandingPalette } from "@meridian/ui-tokens/branding";

import { meridianJson, MeridianApiError } from "@/api/meridianApi";

/**
 * The branding administration surfaces' data layer (M15A.6, M15A.7;
 * BRAND-015, BRAND-018, BRAND-019, BRAND-021).
 *
 * Preview and save go to the same server validator. That is the point of
 * BRAND-018: the result shown before the save has to be the result the save
 * produces, and a client-side approximation of WCAG arithmetic would eventually
 * disagree with the server about a boundary case and tell an organizer their
 * palette was fine right before refusing it.
 *
 * Refusals come back as structured failures, not as a sentence, so the surface
 * can render the failing pair, the measured ratio, and the required ratio for
 * each one (BRAND-015).
 */

export interface ContrastFailure {
  readonly pair: string;
  readonly usage: string;
  readonly foreground: string;
  readonly background: string;
  readonly measured_ratio: number;
  readonly required_ratio: number;
}

export interface BrandingPreviewResult {
  readonly valid: boolean;
  readonly failures: readonly ContrastFailure[];
}

export interface OrganizationBrandingResult {
  readonly organization_id: string;
  readonly display_name: string;
  readonly is_branded: boolean;
  readonly palette: BrandingPalette;
  readonly department_branding_enabled: boolean;
  readonly lettermark: string;
  readonly full_lockup_url: string | null;
  readonly compact_mark_url: string | null;
  readonly editable: boolean;
  readonly branding_updated_at: string | null;
}

export interface DepartmentBrandingResult {
  readonly department_id: string;
  readonly organization_id: string;
  readonly name: string;
  readonly accent: string | null;
  readonly surface: string | null;
  readonly logo_url: string | null;
  readonly lettermark: string;
  readonly branding_updated_at: string | null;
}

/**
 * A refused branding submission.
 *
 * `blocked` separates the two refusals an organizer can hit. A 422 means the
 * colors are wrong and editing them will fix it; a 409 means the edit was
 * attempted somewhere or somewhen it is not allowed — an on-site node, or
 * inside the active event window — and no amount of editing will (BRAND-021).
 */
export class BrandingRejectedError extends Error {
  constructor(
    message: string,
    readonly failures: readonly ContrastFailure[],
    readonly blocked: boolean,
  ) {
    super(message);
    this.name = "BrandingRejectedError";
  }
}

function rethrow(error: unknown): never {
  if (error instanceof MeridianApiError) {
    const body = error.body as
      | { message?: string; failures?: ContrastFailure[] }
      | null;

    throw new BrandingRejectedError(
      body?.message ?? error.message,
      body?.failures ?? [],
      error.status === 409,
    );
  }

  throw error;
}

export async function previewOrganizationPalette(
  organizationId: string,
  palette: BrandingPalette,
): Promise<BrandingPreviewResult> {
  try {
    return await meridianJson<BrandingPreviewResult>(
      "/api/commands/preview-branding",
      {
        method: "POST",
        body: JSON.stringify({ organization_id: organizationId, palette }),
      },
    );
  } catch (error) {
    return rethrow(error);
  }
}

export async function previewDepartmentBranding(
  organizationId: string,
  departmentId: string,
  accent: string | null,
  surface: string | null,
): Promise<BrandingPreviewResult> {
  try {
    return await meridianJson<BrandingPreviewResult>(
      "/api/commands/preview-branding",
      {
        method: "POST",
        body: JSON.stringify({
          organization_id: organizationId,
          department_id: departmentId,
          accent,
          surface,
        }),
      },
    );
  } catch (error) {
    return rethrow(error);
  }
}

export async function saveOrganizationBranding(
  organizationId: string,
  attributes: {
    readonly display_name?: string | null;
    readonly palette?: BrandingPalette | null;
    readonly department_branding_enabled?: boolean;
  },
): Promise<OrganizationBrandingResult> {
  try {
    return await meridianJson<OrganizationBrandingResult>(
      "/api/commands/update-organization-branding",
      {
        method: "POST",
        body: JSON.stringify({ organization_id: organizationId, ...attributes }),
      },
    );
  } catch (error) {
    return rethrow(error);
  }
}

export async function saveDepartmentBranding(
  departmentId: string,
  attributes: { readonly accent?: string | null; readonly surface?: string | null },
): Promise<DepartmentBrandingResult> {
  try {
    return await meridianJson<DepartmentBrandingResult>(
      "/api/commands/update-department-branding",
      {
        method: "POST",
        body: JSON.stringify({ department_id: departmentId, ...attributes }),
      },
    );
  } catch (error) {
    return rethrow(error);
  }
}

export interface BrandingAssetResult {
  readonly attachment_id: string;
  readonly slot: string;
  readonly mime_type: string;
  readonly byte_size: number;
  readonly url: string;
}

/** Branding logo slots (BRAND-004, BRAND-010, BRAND-025). */
export const BRANDING_SLOTS = {
  fullLockup: "full_lockup",
  compactMark: "compact_mark",
  departmentLogo: "department_logo",
  teamLogo: "team_logo",
  eventLogo: "event_logo",
} as const;

export type BrandingSlot =
  (typeof BRANDING_SLOTS)[keyof typeof BRANDING_SLOTS];

/**
 * Which entity a logo belongs to. Exactly one id is sent; the server picks the
 * owner from whichever arrives, and the slot it will accept follows from that.
 */
export interface BrandingAssetOwner {
  readonly organizationId?: string;
  readonly departmentId?: string;
  readonly teamId?: string;
  readonly eventId?: string;
}

/** One event and the mark it carries, for the branding surface (BRAND-028). */
export interface EventBrandingSummary {
  readonly event_id: string;
  readonly name: string;
  readonly lettermark: string;
  readonly archived: boolean;
  readonly logo_url: string | null;
}

/**
 * The organization's events and their marks.
 *
 * A separate authenticated call rather than a field on the branding profile:
 * that profile is read unauthenticated so branding can resolve before a session
 * does, and it publishes only the event an install is locked to. The full list
 * of what an organization is running belongs behind a session.
 */
export async function listEventBranding(
  organizationId: string,
): Promise<readonly EventBrandingSummary[]> {
  try {
    const result = await meridianJson<{ events?: EventBrandingSummary[] }>(
      `/api/organizations/${organizationId}/branding/events`,
    );

    // Defaulted rather than trusted. A 200 carrying a body this did not expect
    // is not worth taking the branding screen down over, and the section
    // renders its empty state from exactly the same value.
    return result.events ?? [];
  } catch (error) {
    return rethrow(error);
  }
}

/** Types and size the server will accept, mirrored for a pre-flight message. */
export const BRANDING_ASSET_ACCEPT = "image/png,image/webp,image/jpeg";
export const BRANDING_ASSET_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Upload a logo, replacing whatever occupied the slot.
 *
 * Sent as multipart rather than base64 JSON so the server sniffs the real
 * bytes: the declared type of an upload is a claim, and this asset is served
 * inline to every signed-in surface.
 */
export async function uploadBrandingAsset(
  owner: BrandingAssetOwner,
  slot: BrandingSlot,
  file: File,
): Promise<BrandingAssetResult> {
  const body = new FormData();
  body.set("slot", slot);
  body.set("logo", file);

  if (owner.eventId) {
    body.set("event_id", owner.eventId);
  } else if (owner.teamId) {
    body.set("team_id", owner.teamId);
  } else if (owner.departmentId) {
    body.set("department_id", owner.departmentId);
  } else if (owner.organizationId) {
    body.set("organization_id", owner.organizationId);
  }

  try {
    return await meridianJson<BrandingAssetResult>(
      "/api/commands/upload-branding-asset",
      { method: "POST", body },
    );
  } catch (error) {
    return rethrow(error);
  }
}

/** The single owner id a command carries, most specific first. */
function ownerIdentity(owner: BrandingAssetOwner): Record<string, string> {
  if (owner.eventId) {
    return { event_id: owner.eventId };
  }

  if (owner.teamId) {
    return { team_id: owner.teamId };
  }

  if (owner.departmentId) {
    return { department_id: owner.departmentId };
  }

  return owner.organizationId ? { organization_id: owner.organizationId } : {};
}

/**
 * Clear the current logo for a slot.
 *
 * "Remove" detaches the reference; the stored asset is not destroyed, because
 * attachments are immutable in Alpha 1 and BRAND-004 does not require
 * preserving — or deleting — a superseded logo.
 */
export async function removeBrandingAsset(
  owner: BrandingAssetOwner,
  slot: BrandingSlot,
): Promise<{ readonly slot: string; readonly attachment_id: null }> {
  try {
    return await meridianJson<{ slot: string; attachment_id: null }>(
      "/api/commands/remove-branding-asset",
      {
        method: "POST",
        body: JSON.stringify({ slot, ...ownerIdentity(owner) }),
      },
    );
  } catch (error) {
    return rethrow(error);
  }
}

/**
 * The tokens a live preview paints with.
 *
 * The same derivation the server and the runtime use, so what an organizer
 * sees in the preview panel is what the product renders — including the action
 * label color, which is the one value that changes non-obviously with the
 * palette.
 */
export function previewTokens(
  palette: BrandingPalette,
): Readonly<Record<string, string>> {
  return organizationTokens(palette);
}

/** A human-readable line for one contrast failure (BRAND-015). */
export function describeFailure(failure: ContrastFailure): string {
  return `${failure.pair}: ${failure.foreground} on ${failure.background} measures ${failure.measured_ratio.toFixed(2)}:1, and ${failure.usage} needs ${failure.required_ratio.toFixed(1)}:1.`;
}
