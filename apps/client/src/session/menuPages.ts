// Pages the reader has kept out of their own menus (M18.69).
//
// The milder half of the page preferences. `hiddenPages` next door records a
// page somebody is done with — gone from the menus and off Home. This one
// records a page they simply do not work out of: it comes out of the Staff and
// Workflows menus and stays on Home, where the reader still finds it, and it
// stays linked, searchable in the command palette, and reachable by address.
//
// That difference is the whole point of a second preference. A menu is a list
// somebody reads a dozen times an event while looking for the one entry they
// need, and it earns its length; Home is the map, and a map is allowed to be
// complete. Somebody who runs Logistics all day and never opens Planning is
// asking for a shorter list, not a smaller product.
//
// Page keys here mean what they mean in the other catalog — `dashboard` is the
// same four surfaces in both — because they are one vocabulary shared with the
// node, and one page a reader may answer two questions about. Neither answer is
// authority. Both filter lists the capability checks already built, and a page
// named here opens under exactly the authorization it always had (CLIENT-006).

import { computed, type ComputedRef } from "vue";

import {
  createPagePreference,
  linksOutside,
  routeNamesFor,
} from "@/session/pagePreferences";

export type MenuPage = {
  /** The key the node stores. Shared vocabulary; do not rename lightly. */
  readonly key: string;
  /** Every route this key takes out of the menus. */
  readonly routeNames: readonly string[];
};

/**
 * Every menu entry a reader may put away, in the order the menus build them:
 * the personal pages first, then the workflow hubs.
 *
 * No labels here, deliberately. Settings names each row with the label the menu
 * itself uses, read off the link it is offering to remove, so the control and
 * the thing it controls cannot end up calling the same page two names — and so
 * a reader is only ever offered the entries their own session actually built.
 *
 * `MenuPageCatalog` on the node holds the authoritative key list. It knows
 * nothing about the routes below; a key is an agreed name, and which routes it
 * covers is this client's business.
 */
export const MENU_PAGES: readonly MenuPage[] = [
  { key: "me", routeNames: ["staff.me"] },
  { key: "event-horizon", routeNames: ["staff.event-horizon"] },
  { key: "event-info", routeNames: ["events.info"] },
  /*
   * The dashboards, as one key — the same four surfaces `HIDEABLE_PAGES`
   * covers. Two of them are never in a menu, and they are listed anyway so the
   * key means one page rather than "the menu half of a page".
   */
  {
    key: "dashboard",
    routeNames: [
      "staff.dashboard",
      "events.departments.show",
      "organizer.dashboard",
      "ims.dashboard",
    ],
  },
  { key: "shift-board", routeNames: ["staff.shifts.index"] },
  { key: "field-reports", routeNames: ["staff.field-reports.index"] },
  { key: "trainings", routeNames: ["events.departments.trainings.index"] },
  { key: "department-overview", routeNames: ["events.departments.overview"] },
  { key: "team-overview", routeNames: ["events.departments.teams.show"] },
  { key: "planning", routeNames: ["events.departments.planning"] },
  { key: "logistics", routeNames: ["events.departments.logistics"] },
  { key: "operations", routeNames: ["events.departments.operations"] },
  { key: "incidents", routeNames: ["ims.incidents.index"] },
  { key: "admin", routeNames: ["events.departments.teams.index"] },
];

/**
 * Nothing starts out of the menus.
 *
 * The node says the same, and this copy is what Settings renders from before a
 * session has resolved. A reader at their first event is shown the whole of
 * what they may work out of — a menu somebody has to assemble before it is
 * useful is a menu that was empty when it mattered.
 */
function defaultMenuHiddenPageKeys(): Set<string> {
  return new Set();
}

const preference = createPagePreference({
  field: "menu_hidden_pages",
  commandType: "set-menu-page-visibility",
  defaults: defaultMenuHiddenPageKeys,
});

/** The page keys the reader currently keeps out of their menus. */
export const menuHiddenPageKeys: ComputedRef<ReadonlySet<string>> =
  preference.hiddenKeys;

/** Whether this page still appears in the reader's menus. */
export function pageInMenu(key: string): boolean {
  return !preference.hidden(key);
}

/** Every route name currently out of the menus. */
export const menuHiddenRouteNames: ComputedRef<ReadonlySet<string>> = computed(
  () => routeNamesFor(MENU_PAGES, menuHiddenPageKeys.value),
);

/**
 * The menu page a link belongs to, or null for a link no key covers.
 *
 * Null is a real answer rather than an oversight. Home lists pages the menus
 * never carry — Acknowledgments, the document library, every department and
 * organization page — and there is nothing to offer a reader about their
 * position in a menu they are not in.
 */
export function menuPageKeyFor(routeName: string): string | null {
  return (
    MENU_PAGES.find((page) => page.routeNames.includes(routeName))?.key ?? null
  );
}

/**
 * Drop the links the reader keeps out of their menus.
 *
 * Applied where a menu is drawn and nowhere else. Home builds from the same
 * link lists and does not call this: a page taken out of a menu is still on the
 * map, and filtering it out of both would make this preference the other one.
 */
export function menuLinks<T extends { readonly to: { readonly name: string } }>(
  links: readonly T[],
): T[] {
  return linksOutside(links, menuHiddenRouteNames.value);
}

/**
 * Take a page out of the reader's menus, or put it back.
 *
 * Connected-only for the same reason its sibling is: the preference lives on
 * the account so it follows the reader to their next device, and a queued
 * change is a menu that disagrees with itself everywhere else until the queue
 * drains. A failed write rolls the menu back and throws, so Settings can say
 * the change did not land rather than showing a state the account does not
 * hold.
 */
export function setPageInMenu(key: string, inMenu: boolean): Promise<void> {
  return preference.setHidden(key, !inMenu);
}

/** Test seam: forget any answer given since the session document was written. */
export function resetMenuPageAnswers(): void {
  preference.reset();
}
