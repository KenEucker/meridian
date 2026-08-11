// The machinery behind a page preference the account carries (M18.69).
//
// Two preferences are built on this, and they answer different questions: a
// reader can put a page away entirely ({@link hiddenPages}) or keep it out of
// their menus while it stays on Home ({@link menuPages}). What they share is not
// the question but how the answer travels, and that part is subtle enough that
// two copies of it would be two things to keep in step.
//
//  - The answer arrives on the session document, so the menu is right on first
//    paint and stays right for a client working from cache.
//  - The write is connected-only and optimistic: the surface moves when the
//    reader decides, settles on whatever the node says a moment later, and
//    rolls back and throws when the node refuses, so a surface can say the
//    change did not land instead of showing a state the account does not hold.
//  - A newer session document supersedes the answer this device is holding,
//    synchronously, so there is no tick in which a menu is drawn from a reply
//    the node has already restated as part of the session.
//
// Nothing here decides authority. A store built here holds page keys the reader
// named and subtracts from a list the capability checks already built; the
// routes it names stay reachable, and arriving at one is enforced by the node
// exactly as it always was (CLIENT-006).

import { computed, ref, watch, type ComputedRef } from "vue";

import type { MeridianCommandType } from "@/outbox/commandCatalog";
import { sendConnectedCommand } from "@/outbox/submitCommand";
import { clientSessionState } from "@/session/clientSession";
import type { SessionPreferences } from "@/session/sessionDocument";

export type PagePreference = {
  /** The page keys this preference currently names. */
  readonly hiddenKeys: ComputedRef<ReadonlySet<string>>;
  /** Whether this preference names the given page. */
  hidden(key: string): boolean;
  /** Tell the node, and settle on the answer it gives back. */
  setHidden(key: string, hidden: boolean): Promise<void>;
  /** Test seam: forget any answer given since the session document was written. */
  reset(): void;
};

/**
 * A preference the node stores per account and hands back on the session.
 *
 * `field` names the list on the session document's `preferences` block and is
 * also the field the command's reply carries, because they are the same answer
 * to the same question and a node that named them differently would be
 * describing two things.
 *
 * `defaults` is what to believe when the document carries no list at all — a
 * session resolved by a node from before the field existed, or a cached one
 * written by an older build. It is the catalog's own default, which is the same
 * answer the node gives a reader who has decided nothing, so a menu does not
 * change shape depending on which build last wrote the cache.
 */
export function createPagePreference(options: {
  readonly field: keyof SessionPreferences;
  readonly commandType: MeridianCommandType;
  readonly defaults: () => Set<string>;
}): PagePreference {
  /*
   * The answer this device has been given since the session document was
   * written, or null when the document is still the most recent word.
   *
   * The write returns the reader's whole resolved list, so a successful toggle
   * has a complete answer to adopt and does not have to wait on a session
   * refresh. It is dropped the moment a newer document arrives, because at that
   * point the node has said it again as part of the session.
   */
  const answered = ref<ReadonlySet<string> | null>(null);

  watch(
    () => clientSessionState.document?.refreshed_at ?? null,
    () => {
      answered.value = null;
    },
    // Synchronously, so there is no tick in which the menu is still drawn from
    // an answer the newly arrived document has already superseded.
    { flush: "sync" },
  );

  const hiddenKeys: ComputedRef<ReadonlySet<string>> = computed(() => {
    if (answered.value !== null) {
      return answered.value;
    }

    const stored = clientSessionState.document?.preferences?.[options.field];

    return Array.isArray(stored) ? keySet(stored) : options.defaults();
  });

  async function setHidden(key: string, hidden: boolean): Promise<void> {
    const before = answered.value;
    const optimistic = new Set(hiddenKeys.value);

    if (hidden) {
      optimistic.add(key);
    } else {
      optimistic.delete(key);
    }

    answered.value = optimistic;

    try {
      const result = (await sendConnectedCommand({
        commandType: options.commandType,
        idempotencyKey: commandIdempotencyKey(options.commandType),
        payload: { page_key: key, hidden },
      })) as Record<string, unknown> | null;

      const settled = result?.[options.field];

      if (Array.isArray(settled)) {
        answered.value = keySet(settled);
      }
    } catch (error) {
      answered.value = before;
      throw error;
    }
  }

  return {
    hiddenKeys,
    hidden: (key) => hiddenKeys.value.has(key),
    setHidden,
    reset: () => {
      answered.value = null;
    },
  };
}

/** A page the reader may put away, and the routes their answer covers. */
export type PageRoutes = {
  /** The key the node stores. Shared vocabulary; do not rename lightly. */
  readonly key: string;
  /** Every route this key covers. */
  readonly routeNames: readonly string[];
};

/**
 * The route names a set of page keys covers.
 *
 * A set rather than a list because the nav builders ask it once per link, and a
 * page key the catalog no longer knows simply contributes no routes.
 */
export function routeNamesFor(
  pages: readonly PageRoutes[],
  keys: ReadonlySet<string>,
): Set<string> {
  const routes = new Set<string>();

  for (const page of pages) {
    if (keys.has(page.key)) {
      page.routeNames.forEach((name) => routes.add(name));
    }
  }

  return routes;
}

/**
 * Drop the links whose route the reader has named.
 *
 * Applied after the capability checks that built the list, never before: what
 * somebody may reach and what they want to look at are different questions, and
 * answering them in the other order would make a preference look like an
 * authority decision.
 */
export function linksOutside<T extends { readonly to: { readonly name: string } }>(
  links: readonly T[],
  routeNames: ReadonlySet<string>,
): T[] {
  return links.filter((link) => !routeNames.has(link.to.name));
}

function keySet(values: readonly unknown[]): Set<string> {
  return new Set(values.filter((value): value is string => typeof value === "string"));
}

function commandIdempotencyKey(commandType: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${commandType}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
