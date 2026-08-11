// Pages the reader has put away (M18.69).
//
// A page key is an agreed name shared with the node; the mapping from that name
// to the routes it covers lives here and only here, because routes are the
// client's business. The node stores the answer and never applies it.
//
// This is presentation, and it is worth being explicit about what it is not.
// Hiding a page removes it from the two menus and from the Home directory. It
// does not remove the route, does not refuse the address, and grants nothing:
// a hidden page typed into the bar opens under exactly the authorization it
// always had (CLIENT-006). The nav is derived from capabilities first and
// filtered by preference second, in that order, so a preference can only ever
// subtract from what somebody was already permitted to reach.
//
// The milder question — which of the pages a reader kept do they want in their
// menus — is `menuPages` next door. Both preferences travel the same way, and
// `pagePreferences` holds the part they share.

import { computed, type ComputedRef } from "vue";

import {
  createPagePreference,
  linksOutside,
  routeNamesFor,
} from "@/session/pagePreferences";

export type HideablePage = {
  /** The key the node stores. Shared vocabulary; do not rename lightly. */
  readonly key: string;
  readonly label: string;
  readonly description: string;
  /** Every route this key puts away. */
  readonly routeNames: readonly string[];
  /** What the node does when the reader has decided nothing. */
  readonly hiddenByDefault: boolean;
};

/**
 * The dashboards, as one entry.
 *
 * Four routes answer to it, and they are one page rather than four: the same
 * summary seen from a personal standing, a department's, an organizer's, and
 * Incident Command's. Somebody who does not want a dashboard does not want
 * whichever one their role happens to open, and a reader holding two of those
 * standings would otherwise have to find the same preference twice.
 *
 * Hidden by default, which is the node's answer too — `HideablePageCatalog`
 * holds the authoritative copy and this one is what Settings renders a row
 * from before any session has been resolved.
 */
export const HIDEABLE_PAGES: readonly HideablePage[] = [
  {
    key: "dashboard",
    label: "Dashboards",
    description:
      "The staff, department, organizer, and Incident Command dashboards. Each is a summary of pages this menu already lists.",
    routeNames: [
      "staff.dashboard",
      "events.departments.show",
      "organizer.dashboard",
      "ims.dashboard",
    ],
    hiddenByDefault: true,
  },
];

/** What the catalog hides for a reader who has decided nothing. */
function defaultHiddenPageKeys(): Set<string> {
  return new Set(
    HIDEABLE_PAGES.filter((page) => page.hiddenByDefault).map((page) => page.key),
  );
}

const preference = createPagePreference({
  field: "hidden_pages",
  commandType: "set-page-visibility",
  defaults: defaultHiddenPageKeys,
});

/**
 * The page keys currently hidden.
 *
 * Falls back to the catalog's defaults when the document carries no
 * preferences at all — a session resolved by a node from before this field
 * existed, or a cached one written by an older build. That is the same answer
 * the node gives a reader who has decided nothing, so the menu does not change
 * shape depending on which build last wrote the cache.
 */
export const hiddenPageKeys: ComputedRef<ReadonlySet<string>> =
  preference.hiddenKeys;

/** Whether the reader has put this page away. */
export function pageHidden(key: string): boolean {
  return preference.hidden(key);
}

/** Every route name currently hidden. */
export const hiddenRouteNames: ComputedRef<ReadonlySet<string>> = computed(() =>
  routeNamesFor(HIDEABLE_PAGES, hiddenPageKeys.value),
);

/**
 * Drop the links the reader has put away.
 *
 * Applied after the capability checks that built the list, never before: what
 * somebody may reach and what they want to look at are different questions, and
 * answering them in the other order would make a display preference read like
 * an authority decision.
 */
export function visibleLinks<T extends { readonly to: { readonly name: string } }>(
  links: readonly T[],
): T[] {
  return linksOutside(links, hiddenRouteNames.value);
}

/**
 * Hide a page or restore it.
 *
 * The menu moves as soon as the reader decides, and settles on whatever the
 * node says a moment later — which is usually the same thing, and when it is
 * not, it is the node that is right.
 *
 * Connected-only, and that is a real constraint rather than an oversight. The
 * preference lives on the account so it can follow the reader to another
 * device, and a queued one is a menu that disagrees with itself everywhere else
 * until the queue drains. A failed write rolls the menu back and throws, so the
 * surface can say the change did not land instead of leaving a control showing
 * a state the account does not hold.
 */
export function setPageHidden(key: string, hidden: boolean): Promise<void> {
  return preference.setHidden(key, hidden);
}

/** Test seam: forget any answer given since the session document was written. */
export function resetHiddenPageAnswers(): void {
  preference.reset();
}
