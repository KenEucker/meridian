// Branding follows the session's organization (M16.7; CLIENT-011, CLIENT-014;
// technical spec 11A.3; BRAND-002, BRAND-022).
//
// The client used to resolve branding for a hardcoded organization id, because
// nothing else knew which organization it was in. The session response knows:
// the node's lock decides the event, the event decides the organization, and
// `sessionOrganizationId` reads that out. Wiring branding to it as a watch
// rather than as a call at each site means one rule covers every way the
// organization can change — booting from the durable cache, the node's first
// answer replacing it, and a context switch — and none of them needs its own
// branding step.
//
// Nothing is awaited. `loadBrandingProfile` applies the cached profile for the
// new organization synchronously, or Meridian's own identity when it has none,
// so a switch never paints the previous organization's colors while the next
// profile loads.

import { watch } from "vue";

import { loadBrandingProfile, resetToMeridian } from "@/branding/brandingProfile";
import { sessionOrganizationId } from "@/session/sessionContext";

/**
 * Resolve branding for whichever organization the session is in, and keep
 * resolving it as that changes. Returns the stop handle.
 */
export function followSessionBranding(): () => void {
  return watch(
    sessionOrganizationId,
    (organizationId, previous) => {
      if (organizationId === previous) {
        return;
      }

      if (organizationId === null) {
        /*
         * Losing the organization means losing the right to display it. A
         * signed-out client, or one whose cached session has outlived its event
         * window, returns to Meridian's own identity rather than keeping the
         * last organization's logo and palette on screen.
         *
         * Skipped on the first evaluation, when there is no previous
         * organization: the document is already Meridian's and resetting would
         * only discard a profile a surface had deliberately installed.
         */
        if (previous !== undefined) {
          resetToMeridian();
        }

        return;
      }

      void loadBrandingProfile(organizationId);
    },
    { immediate: true },
  );
}

/**
 * Re-resolve the current organization's branding.
 *
 * For a surface that has just changed something the profile carries — a
 * department logo, most often — and needs the shell header and every other mark
 * to pick it up without a reload. A client with no organization has nothing to
 * resolve and does nothing.
 */
export async function reloadSessionBranding(): Promise<void> {
  const organizationId = sessionOrganizationId.value;

  if (organizationId === null) {
    return;
  }

  await loadBrandingProfile(organizationId);
}
