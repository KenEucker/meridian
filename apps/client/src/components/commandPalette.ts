// The command palette's results, its matching, and the keys that open it
// (M18.33; UI contract 7.1, 7.2; operating guide 10; MAP-018).
//
// The palette is a second way into pages somebody can already reach, not a
// second answer about what they may reach. Every destination it offers comes out
// of `useNavigationSections` — the same derivation the home directory and the
// shell menus render from — so a page appears here exactly when the capability
// codes in the session response permit it, and disappears from all three at once
// when a grant is taken away (CLIENT-004, CLIENT-005). Nothing in this module
// decides authority, and the node refuses the request regardless of what was
// listed (CLIENT-006).
//
// That is also how contract 7.2's filter list is satisfied. Authenticated user,
// organization, event, department, role, and permission are the dimensions
// `useNavigationSections` already resolves from; kiosk/trusted workstation state
// is the branch below, which builds a Kiosk's results from the workstation
// rather than from a personal session; and the operations window reaches the
// results through the session document that carries it.
//
// Two exclusions are worth naming because they are rules rather than omissions.
//
//  - **Camps and map places are not here** (MAP-018). No navigation entry names
//    one, and this module adds no source of its own, so the only way a camp
//    could reach the palette is by somebody putting one in the navigation. Map
//    search stays in the scoped panel on the map surface (MAP-019).
//  - **IMS results follow IC standing.** The Incidents workspace, the IC
//    dashboard, and IMS Field Reports are navigation entries gated on
//    `incidents.view`, so a reader without IC standing for the event's Incident
//    Command department never sees one. There is no record search here to leak a
//    second way.
//
// Records — incidents, Field Reports, documents by title — are not part of this
// task. Operating guide 10.3 offers them as something the palette *may* include;
// what it requires, and what UI contract 7.1 and 7.2 specify, is actions and
// navigation, which is what this builds.

import { computed, inject, type ComputedRef, type Ref } from "vue";
import { routerKey, type RouteLocationRaw, type Router } from "vue-router";

import type { MeridianAppConfig } from "@/app/appConfig";
import { useNavigationSections } from "@/components/workflowLinks";
import { signOut, signedIn } from "@/session/apiLogin";
import { kioskContextPinned } from "@/session/kioskContext";
import {
  selectSessionDepartment,
  selectedSessionDepartment,
  sessionDepartmentAccesses,
} from "@/session/sessionAccess";
import { workstationSessionState } from "@/session/workstationSession";

/**
 * What activating a result does.
 *
 * The contract's suggested result model carries `type => 'navigation'` beside a
 * URL, which is the same split: a result either goes somewhere or does
 * something.
 */
export type CommandPaletteResultType = "navigation" | "action";

export interface CommandPaletteResult {
  /** Stable within one render, and the id the active option is named by. */
  readonly id: string;
  readonly type: CommandPaletteResultType;
  /** The heading this result is listed under. Results are grouped by it. */
  readonly group: string;
  readonly label: string;
  readonly description: string;
  /**
   * The keyboard shortcut this result has of its own, shown beside it
   * (operating guide 10.6).
   *
   * Null for every Alpha 1 result, as it is in the contract's own example: the
   * palette's `Ctrl+K` / `Cmd+K` / `/` are how the palette opens, not shortcuts
   * for the things inside it, and no other surface has claimed a key.
   */
  readonly shortcut: string | null;
  /** Where a navigation result goes. Null for an action. */
  readonly to: RouteLocationRaw | null;
  /** What an action does. Null for a navigation result. */
  readonly run: (() => void | Promise<void>) | null;
}

export interface CommandPaletteGroup {
  readonly group: string;
  readonly results: readonly CommandPaletteResult[];
}

/** The heading actions are listed under, after every navigation group. */
const ACTIONS_GROUP = "Actions";

/**
 * Every result the signed-in user may reach right now.
 *
 * Takes the shell's configuration rather than reading the module-level one, for
 * the same reason `AppShell` takes it as a prop: which UI mode this is decides
 * which of the two result sets applies, and a mode fixed at build time is still
 * something a test has to be able to state.
 */
export function useCommandPaletteResults(
  config: Ref<MeridianAppConfig> | ComputedRef<MeridianAppConfig>,
): ComputedRef<CommandPaletteResult[]> {
  const navigationSections = useNavigationSections();
  /*
   * Injected rather than taken from `useRouter`, because the shell mounts in
   * tests without a router installed and a palette that threw there would make
   * every other shell assertion depend on one.
   */
  const router = inject(routerKey, null);

  return computed(() => {
    if (config.value.uiMode === "kiosk") {
      return kioskResults();
    }

    const results: CommandPaletteResult[] = [];

    for (const section of navigationSections.value) {
      for (const link of section.links) {
        results.push({
          id: `navigation:${section.title}:${link.label}`,
          type: "navigation",
          group: section.title,
          label: link.pageLabel ?? link.label,
          description: link.description ?? "",
          shortcut: null,
          to: link.to,
          run: null,
        });
      }
    }

    /*
     * Actions last, and only where the session says they can be performed —
     * "unavailable actions should be hidden" (operating guide 10.3, 18.1).
     *
     * A palette with no destinations gets none of these either. Before a session
     * resolves there is nothing to switch between and nothing this device is
     * signed in to, and offering a control there would be offering one that
     * disposes of nothing.
     */
    if (results.length === 0) {
      return results;
    }

    /*
     * Workstation sign-in (M18.61; UI contract 12.3 `staff.workstation-code`).
     * A palette entry rather than a menu entry, plus the link on Me: the
     * moment somebody wants this they are standing at a kiosk, and the palette
     * is the fastest way there by name. Absent in Kiosk mode by construction —
     * this branch never runs there — because a workstation does not sign its
     * users in elsewhere.
     */
    results.push({
      id: "navigation:Staff:Workstation Sign-in",
      type: "navigation",
      group: "Staff",
      label: "Workstation Sign-in",
      description:
        "Sign in to a shared workstation: scan its code, or generate a login code.",
      shortcut: null,
      to: { name: "staff.workstation-code" },
      run: null,
    });

    results.push(...departmentSwitchActions(router));

    /*
     * Signing out of a personal device (M16.11; AUTH-018). Which of sign in and
     * sign out is offered follows from whether this device holds a token rather
     * than from whether a session document is installed, the same rule the shell
     * menu applies — a cached session names a user while the device holds no
     * credential, and "sign out" there would dispose of nothing. Signing *in* is
     * a page rather than an action and stays out of this list.
     */
    if (signedIn.value) {
      results.push({
        id: "action:sign-out",
        type: "action",
        group: ACTIONS_GROUP,
        label: "Sign out",
        description: "End this device's session and return to sign in.",
        shortcut: null,
        to: null,
        run: async () => {
          await signOut();
          await router?.push?.({ name: "login" });
        },
      });
    }

    return results;
  });
}

/**
 * Switching to another of the user's departments.
 *
 * The same act the shell's department marks and its user menu perform, offered
 * by name for somebody whose hands are on the keyboard. Absent for a user with
 * one department, because a switcher with a single destination is a control with
 * nothing to do.
 */
function departmentSwitchActions(router: Router | null): CommandPaletteResult[] {
  const current = selectedSessionDepartment.value?.departmentId ?? null;

  return sessionDepartmentAccesses.value
    .filter((department) => department.departmentId !== current)
    .map((department) => ({
      id: `action:switch-department:${department.departmentId}`,
      type: "action" as const,
      group: ACTIONS_GROUP,
      label: `Switch to ${department.departmentLabel}`,
      description: "Work this event in another of your departments.",
      shortcut: null,
      to: null,
      run: () => {
        selectSessionDepartment(department.departmentId);
        void router?.push?.({ name: "staff.me" });
      },
    }));
}

/**
 * What a shared workstation offers (UI-019, UI-020; kiosk guide 11).
 *
 * Built from the machine rather than from a personal session, because that is
 * what a Kiosk has: a workstation pinned to an organization and event, and
 * somebody standing at it for five minutes. Kiosk guide 11 requires results
 * limited by trusted workstation state, and the two states produce different
 * lists — an unpinned machine has only setup, and a locked one has only the way
 * in.
 *
 * Readiness and Health are in every list because UI-011 puts them in every UI
 * mode: they are facts about the device, they need no context, and they are what
 * somebody checks when the machine is the thing that is wrong.
 *
 * Ending the session is deliberately not here. The session bar holds that
 * control, next to the name it ends (technical spec 13.3), and a second way to
 * end somebody else's session from a search box is not an improvement.
 */
function kioskResults(): CommandPaletteResult[] {
  const results: CommandPaletteResult[] = [];

  if (kioskContextPinned.value) {
    if (workstationSessionState.status === "active") {
      results.push(
        kioskNavigation(
          "kiosk.shift-board",
          "Shift board",
          "Check staff in and out at this workstation.",
        ),
        kioskNavigation(
          "kiosk.switch-user",
          "Switch user",
          "End this session so the next person can sign in.",
        ),
      );
    } else {
      results.push(
        kioskNavigation(
          "kiosk.workstation-login",
          "Sign in to this workstation",
          "Enter the login code issued for this workstation.",
        ),
      );
    }

    results.push(
      kioskNavigation(
        "kiosk.home",
        "Workstation home",
        "This workstation's tasks and status.",
      ),
    );
  }

  results.push(
    kioskNavigation(
      "kiosk.setup",
      "Workstation setup",
      "The organization, event, and department this machine is pinned to.",
    ),
    kioskNavigation("readiness", "Readiness", "Device readiness checks."),
    kioskNavigation("settings.about", "Health", "Client and local server diagnostics."),
  );

  return results;
}

function kioskNavigation(
  routeName: string,
  label: string,
  description: string,
): CommandPaletteResult {
  return {
    id: `navigation:Workstation:${routeName}`,
    type: "navigation",
    group: "Workstation",
    label,
    description,
    shortcut: null,
    to: { name: routeName },
    run: null,
  };
}

/**
 * The results that answer what somebody typed.
 *
 * An empty query answers with everything, because the palette is a directory
 * before it is a search: somebody who opens it without typing is looking for
 * what is there. Every whitespace-separated term has to appear somewhere in the
 * result, so "rangers shift" narrows rather than widening, and the order of the
 * terms does not matter.
 *
 * Matching is over the label, the description, and the group heading. Ranking
 * prefers a label match over a description one so that typing a page's name puts
 * that page first, and is otherwise stable — two results that match equally well
 * stay in the order the permission-derived list produced, which is the order the
 * home directory reads in.
 */
export function matchCommandPaletteResults(
  results: readonly CommandPaletteResult[],
  query: string,
): CommandPaletteResult[] {
  const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

  if (terms.length === 0) {
    return [...results];
  }

  const scored: Array<{ result: CommandPaletteResult; score: number; order: number }> = [];

  results.forEach((result, order) => {
    const label = result.label.toLowerCase();
    const haystack = `${label} ${result.description.toLowerCase()} ${result.group.toLowerCase()}`;

    if (!terms.every((term) => haystack.includes(term))) {
      return;
    }

    const first = terms[0] ?? "";
    const score = label.startsWith(first) ? 0 : label.includes(first) ? 1 : 2;

    scored.push({ result, score, order });
  });

  return scored
    .sort((left, right) => left.score - right.score || left.order - right.order)
    .map((entry) => entry.result);
}

/**
 * The same results, grouped by type for display (operating guide 10.3).
 *
 * Groups appear in the order their first result does, which puts the navigation
 * groups in the home directory's own order and actions last. Navigation keeps
 * the directory's headings rather than collapsing into one "Navigation" group:
 * a reader scanning for a page is looking for the section it lives in, and
 * "Organization pages" tells them more than the word navigation does while
 * still holding actions apart from destinations.
 */
export function groupCommandPaletteResults(
  results: readonly CommandPaletteResult[],
): CommandPaletteGroup[] {
  const groups: CommandPaletteGroup[] = [];
  const byName = new Map<string, CommandPaletteResult[]>();

  for (const result of results) {
    let bucket = byName.get(result.group);

    if (bucket === undefined) {
      bucket = [];
      byName.set(result.group, bucket);
      groups.push({ group: result.group, results: bucket });
    }

    bucket.push(result);
  }

  return groups;
}

/**
 * Whether this keystroke opens the palette (UI contract 7.1).
 *
 * `Ctrl+K` on Windows and Linux, `Cmd+K` on macOS — read from the modifier
 * rather than from the platform string, because the platform is not something
 * the client should be sniffing and either modifier means the same thing here.
 *
 * `/` only when the user is not typing in a field, which is the whole of the
 * third rule: a slash inside a search box, a Field Report body, or an incident
 * note is a character somebody meant to type, and stealing it would make the
 * palette a bug in every form in the product.
 */
export function opensCommandPalette(event: KeyboardEvent): boolean {
  if (event.altKey) {
    return false;
  }

  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
    return true;
  }

  return (
    event.key === "/" &&
    !event.ctrlKey &&
    !event.metaKey &&
    !isTypingTarget(event.target)
  );
}

/**
 * Whether this event landed somewhere a keystroke is text.
 *
 * Checkboxes, radios, and buttons are inputs that nobody types into, so a slash
 * pressed while one has focus is still a slash pressed outside a field.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  if (target.isContentEditable) {
    return true;
  }

  if (target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) {
    return true;
  }

  if (target instanceof HTMLInputElement) {
    return !["button", "checkbox", "radio", "reset", "submit", "range", "color"].includes(
      target.type,
    );
  }

  // A custom control that told assistive technology it takes text is taken at
  // its word, for the same reason a native one is.
  return ["textbox", "searchbox", "combobox"].includes(
    target.getAttribute("role") ?? "",
  );
}
