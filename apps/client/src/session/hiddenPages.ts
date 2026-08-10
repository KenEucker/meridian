// Pages the reader has chosen to put away (M18.69).
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

import { computed, ref, watch, type ComputedRef } from "vue";

import { clientSessionState } from "@/session/clientSession";
import { sendConnectedCommand } from "@/outbox/submitCommand";

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

/**
 * The answer this device has been given since the session document was written,
 * or null when the document is still the most recent word.
 *
 * The write returns the reader's whole resolved list, so a successful toggle
 * has a complete answer to adopt and does not have to wait on a session
 * refresh. It is dropped the moment a newer document arrives, because at that
 * point the node has said it again as part of the session.
 */
const answeredHiddenPages = ref<ReadonlySet<string> | null>(null);

watch(
  () => clientSessionState.document?.refreshed_at ?? null,
  () => {
    answeredHiddenPages.value = null;
  },
  // Synchronously, so there is no tick in which the menu is still drawn from an
  // answer the newly arrived document has already superseded.
  { flush: "sync" },
);

/** What the catalog hides for a reader who has decided nothing. */
function defaultHiddenPageKeys(): Set<string> {
  return new Set(
    HIDEABLE_PAGES.filter((page) => page.hiddenByDefault).map((page) => page.key),
  );
}

/**
 * The page keys currently hidden.
 *
 * Falls back to the catalog's defaults when the document carries no
 * preferences at all — a session resolved by a node from before this field
 * existed, or a cached one written by an older build. That is the same answer
 * the node gives a reader who has decided nothing, so the menu does not change
 * shape depending on which build last wrote the cache.
 */
export const hiddenPageKeys: ComputedRef<ReadonlySet<string>> = computed(() => {
  if (answeredHiddenPages.value !== null) {
    return answeredHiddenPages.value;
  }

  const stored = clientSessionState.document?.preferences?.hidden_pages;

  return Array.isArray(stored)
    ? new Set(stored.filter((key): key is string => typeof key === "string"))
    : defaultHiddenPageKeys();
});

/** Whether the reader has put this page away. */
export function pageHidden(key: string): boolean {
  return hiddenPageKeys.value.has(key);
}

/**
 * Every route name currently hidden.
 *
 * A set rather than a list because the nav builders ask it once per link, and
 * a page key the catalog no longer knows simply contributes no routes.
 */
export const hiddenRouteNames: ComputedRef<ReadonlySet<string>> = computed(() => {
  const routes = new Set<string>();

  for (const page of HIDEABLE_PAGES) {
    if (hiddenPageKeys.value.has(page.key)) {
      page.routeNames.forEach((name) => routes.add(name));
    }
  }

  return routes;
});

/**
 * Drop the links the reader has put away.
 *
 * Applied after the capability checks that built the list, never before: what
 * somebody may reach and what they want to look at are different questions, and
 * answering them in the other order would make a preference look like an
 * authority decision.
 */
export function visibleLinks<T extends { readonly to: { readonly name: string } }>(
  links: readonly T[],
): T[] {
  const hidden = hiddenRouteNames.value;

  return links.filter((link) => !hidden.has(link.to.name));
}

function commandIdempotencyKey(commandType: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${commandType}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
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
export async function setPageHidden(
  key: string,
  hidden: boolean,
): Promise<void> {
  const before = answeredHiddenPages.value;
  const optimistic = new Set(hiddenPageKeys.value);

  if (hidden) {
    optimistic.add(key);
  } else {
    optimistic.delete(key);
  }

  answeredHiddenPages.value = optimistic;

  try {
    const result = (await sendConnectedCommand({
      commandType: "set-page-visibility",
      idempotencyKey: commandIdempotencyKey("set-page-visibility"),
      payload: { page_key: key, hidden },
    })) as { readonly hidden_pages?: unknown } | null;

    const answered = result?.hidden_pages;

    if (Array.isArray(answered)) {
      answeredHiddenPages.value = new Set(
        answered.filter((entry): entry is string => typeof entry === "string"),
      );
    }
  } catch (error) {
    answeredHiddenPages.value = before;
    throw error;
  }
}

/** Test seam: forget any answer given since the session document was written. */
export function resetHiddenPageAnswers(): void {
  answeredHiddenPages.value = null;
}
