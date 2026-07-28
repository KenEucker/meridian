/**
 * Organization branding for the desktop wrapper's window icon (BRAND-003A).
 *
 * BRAND-003 keeps Meridian's identity on desktop chrome and installers, and
 * BRAND-003A carves out exactly one thing from that: the running window and
 * taskbar icon of an application that is locked to an event. The carve-out is
 * narrow on purpose. The packaged icon, the installer, the executable
 * metadata, and the OS-facing application name identify the *software*, and
 * they have to stay correct on a machine that is not running an event and on a
 * machine that serves more than one organization.
 *
 * "Locked to an event" is read from the node, not from the signed-in user: a
 * Kiosk sitting at a gate has no user, and the node is what knows which event
 * this install is running.
 *
 * These functions are pure so they can be unit tested without an Electron
 * runtime; `main.ts` does the fetching and the `setIcon` call.
 */

/** The node identity fields `GET /api/health` reports. */
export interface NodeIdentity {
  node_role?: string | null;
  organization_id?: string | null;
  event_id?: string | null;
}

/** The subset of the branding manifest the wrapper needs. */
export interface BrandingManifest {
  is_branded?: boolean | null;
  assets?: ReadonlyArray<{ slot?: string | null; url?: string | null }> | null;
}

/**
 * The organization whose branding this window should show, or null when the
 * window must keep Meridian's icon.
 *
 * Null covers three distinct cases, and they are all "keep Meridian":
 * a node that names no organization, a node that is not locked to an event,
 * and a node whose identity could not be read at all.
 */
export function resolveBrandedOrganizationId(
  health: NodeIdentity | null,
): string | null {
  const organizationId = health?.organization_id?.trim();
  const eventId = health?.event_id?.trim();

  if (!organizationId || !eventId) {
    return null;
  }

  return organizationId;
}

/**
 * The asset URL to use for the window icon, or null to keep Meridian's.
 *
 * Compact mark first, then the full lockup. A window icon is rendered at
 * 32 pixels or less, where a wide lockup becomes an unreadable smear, so the
 * square mark is preferred whenever one exists — the same precedence the
 * application header uses.
 *
 * An organization with no branding profile keeps Meridian's icon even if it
 * somehow has an asset row, because an unbranded organization has not asked to
 * replace Meridian's identity anywhere (BRAND-002).
 */
export function resolveWindowIconUrl(
  manifest: BrandingManifest | null,
): string | null {
  if (!manifest?.is_branded) {
    return null;
  }

  const assets = manifest.assets ?? [];
  const bySlot = (slot: string): string | null =>
    assets.find((asset) => asset?.slot === slot)?.url?.trim() || null;

  return bySlot("compact_mark") ?? bySlot("full_lockup");
}

/** The manifest URL for an organization on a given server. */
export function brandingManifestUrl(
  serverUrl: string,
  organizationId: string,
): string {
  return new URL(
    `branding/${encodeURIComponent(organizationId)}/manifest.json`,
    serverUrl,
  ).toString();
}
