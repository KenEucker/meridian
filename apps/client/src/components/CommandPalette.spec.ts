import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory, type Router } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { appConfigForUiMode, type UiMode } from "@/app/appConfig";
import AppShell from "@/components/AppShell.vue";
import {
  groupCommandPaletteResults,
  isTypingTarget,
  matchCommandPaletteResults,
  opensCommandPalette,
  type CommandPaletteResult,
} from "@/components/commandPalette";
import { resetToMeridian } from "@/branding/brandingProfile";
import { recordNodeAnswered, resetNodeReachability } from "@/offline/nodeReachability";
import { routes } from "@/router";
import { adoptHeldApiToken } from "@/session/apiLogin";
import { clearApiToken } from "@/session/apiToken";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import { resetKioskContext, resolveKioskContext } from "@/session/kioskContext";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_ORGANIZATION_ID,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
  selectedSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionDocument, SessionRole } from "@/session/sessionDocument";
import { configureSharedWorkstationId } from "@/session/workstationIdentity";
import {
  enterWorkstationLoginCode,
  resetWorkstationSession,
} from "@/session/workstationSession";

vi.mock("@/field-reports/syncFieldReportOutbox", () => ({
  syncFieldReportOutbox: vi.fn(async () => undefined),
}));

/*
 * The command palette (M18.33; UI contract 7.1, 7.2; MAP-018).
 *
 * Two halves, tested two ways. The keys that open it and the matching that
 * narrows it are pure functions, so they are exercised directly. What may appear
 * in it is a property of the session, so it is read back out of a mounted shell
 * — the same route a person takes — and every case installs a document of the
 * shape `GET /api/me` returns rather than reaching a server (CLIENT-024).
 */

const EVENT_ID = "event-1";
const DEPARTMENT_ID = "dept-1";
const TEAM_ID = "team-1";

/**
 * A session for one user, in one department, holding exactly what is asked for.
 *
 * Deliberately minimal, for the same reason `workflowLinks.spec.ts` builds one:
 * a document carrying one capability is the only way to say "this result appears
 * because of this code" without a second grant quietly keeping it on screen.
 */
function sessionWith(capabilities: readonly string[] = []): SessionDocument {
  const role: SessionRole = {
    role_code: "staff",
    role_name: "Test Role",
    scope_type: "department",
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    department_id: DEPARTMENT_ID,
    team_id: TEAM_ID,
    team_name: "Team One",
    event_id: EVENT_ID,
    team_grant_id: "grant-1",
    reason: "Test grant.",
    capabilities: [...capabilities],
  };

  return localFieldSessionDocument({
    roles: [role],
    capabilities: [...capabilities],
    events: [
      {
        id: EVENT_ID,
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        name: "Test Event",
        slug: "test-event",
        status: "published",
        timezone: "UTC",
        starts_at: null,
        ends_at: null,
        active_event_window_starts_at: null,
        active_event_window_ends_at: null,
        is_node_locked: true,
      },
    ],
    departments: [
      {
        id: DEPARTMENT_ID,
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        name: "Test Department",
        code: "TEST",
        membership_status: "active",
        archived_at: null,
      },
    ],
    teams: [
      {
        id: TEAM_ID,
        department_id: DEPARTMENT_ID,
        organization_id: LOCAL_FIELD_ORGANIZATION_ID,
        name: "Team One",
        code: "ONE",
        is_default: false,
        is_lead: false,
        archived_at: null,
      },
    ],
    context: {
      organization_id: LOCAL_FIELD_ORGANIZATION_ID,
      event_id: EVENT_ID,
      department_id: DEPARTMENT_ID,
      node_locked: true,
      node_locked_event_id: EVENT_ID,
      switching_available: false,
    },
  });
}

function mountShell(uiMode: UiMode = "admin") {
  const router: Router = createRouter({ history: createWebHistory(), routes });

  const wrapper = mount(AppShell, {
    props: { config: appConfigForUiMode(uiMode) },
    global: { plugins: [router] },
    attachTo: document.body,
  });

  return { wrapper, router };
}

/** Open the palette the way a pointer user does, and read what it offers. */
async function openPalette(uiMode: UiMode = "admin") {
  const mounted = mountShell(uiMode);
  await flushPromises();
  await mounted.wrapper.get(".app-shell__command-palette").trigger("click");
  await flushPromises();

  return mounted;
}

function optionLabels(wrapper: ReturnType<typeof mountShell>["wrapper"]): string[] {
  return wrapper
    .findAll(".command-palette__result-label")
    .map((option) => option.text());
}

beforeEach(() => {
  installLocalFieldSession();
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
  recordNodeAnswered();
  window.history.replaceState({}, "", "/");
});

afterEach(() => {
  resetToMeridian();
  resetWorkstationSession();
  resetKioskContext();
  resetSelectedSessionDepartment();
  resetNodeReachability();
  clearClientSession();
  clearApiToken();
  adoptHeldApiToken();
  document.body.innerHTML = "";
  vi.clearAllMocks();
});

/*
 * UI contract 7.1: `Ctrl+K` on Windows/Linux, `Cmd+K` on macOS, and `/` only
 * when the user is not typing in a field.
 */
describe("command palette shortcuts", () => {
  function keydown(init: KeyboardEventInit & { readonly target?: Element }) {
    const event = new KeyboardEvent("keydown", init);

    if (init.target) {
      Object.defineProperty(event, "target", { value: init.target });
    }

    return event;
  }

  it("opens on Ctrl+K and on Cmd+K", () => {
    expect(opensCommandPalette(keydown({ key: "k", ctrlKey: true }))).toBe(true);
    expect(opensCommandPalette(keydown({ key: "k", metaKey: true }))).toBe(true);
    // Shift is somebody holding the key down harder, not a different chord.
    expect(
      opensCommandPalette(keydown({ key: "K", ctrlKey: true, shiftKey: true })),
    ).toBe(true);
  });

  it("leaves other chords alone", () => {
    expect(opensCommandPalette(keydown({ key: "k" }))).toBe(false);
    expect(opensCommandPalette(keydown({ key: "k", altKey: true }))).toBe(false);
    expect(
      opensCommandPalette(keydown({ key: "k", ctrlKey: true, altKey: true })),
    ).toBe(false);
    expect(opensCommandPalette(keydown({ key: "j", ctrlKey: true }))).toBe(false);
  });

  it("opens on / when the user is not typing in a field", () => {
    const button = document.createElement("button");

    expect(opensCommandPalette(keydown({ key: "/", target: button }))).toBe(true);
  });

  it("leaves / alone inside anything somebody types into", () => {
    // The whole of the third rule. A slash inside a search box, a Field Report
    // body, or an incident note is a character somebody meant to type, and
    // stealing it would make the palette a bug in every form in the product.
    const text = document.createElement("input");
    const textarea = document.createElement("textarea");
    const select = document.createElement("select");
    const editable = document.createElement("div");
    Object.defineProperty(editable, "isContentEditable", { value: true });
    const custom = document.createElement("div");
    custom.setAttribute("role", "textbox");

    for (const target of [text, textarea, select, editable, custom]) {
      expect(opensCommandPalette(keydown({ key: "/", target }))).toBe(false);
      expect(isTypingTarget(target)).toBe(true);
    }
  });

  it("treats an input nobody types into as outside a field", () => {
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";

    expect(isTypingTarget(checkbox)).toBe(false);
    expect(opensCommandPalette(keydown({ key: "/", target: checkbox }))).toBe(true);
  });
});

/*
 * UI contract 7.2: results filtered by authenticated user, organization, event,
 * department, role, permission, kiosk state, and the operations window. All of
 * those are dimensions the session response already resolves, so what is
 * asserted here is that the palette reads that answer and adds nothing of its
 * own.
 */
describe("command palette results", () => {
  it("offers no unpermitted destination", async () => {
    installClientSession(sessionWith(["department.administer"]), "network");
    selectSessionDepartment(DEPARTMENT_ID);

    const { wrapper } = await openPalette();
    const labels = optionLabels(wrapper);

    // What `department.administer` opens: the Admin workflow and the pages
    // inside it, plus the two the same code reaches beside it.
    expect(labels).toContain("Admin");
    expect(labels).toContain("Shifts");
    expect(labels).toContain("Roster");
    expect(labels).toContain("Deployments");

    // And nothing else a department or organization capability would have.
    for (const absent of [
      "Overview",
      "Planning",
      "Logistics",
      "Operations",
      "Incidents",
      "Incident Command dashboard",
      "Field Reports",
      "Equipment",
      "Credits",
      "Branding",
      "Credentials",
      "Exports",
      "Waivers",
      "Departments",
      "Configuration",
      "Audit",
      "Applications",
      "Profile requests",
      "Organizer dashboard",
    ]) {
      expect(labels).not.toContain(absent);
    }

    wrapper.unmount();
  });

  it("hides IMS destinations from a reader without IC standing, and offers them with it", async () => {
    // Contract 7.2: "IMS results must not appear unless the user has the
    // required IC role for the event's configured IC department." The palette
    // has no record search to leak a second way, so the whole of the rule is
    // that the three IMS pages follow `incidents.view`.
    installClientSession(sessionWith([]), "network");
    selectSessionDepartment(DEPARTMENT_ID);

    const withoutIc = await openPalette();
    await flushPromises();

    for (const absent of [
      "Incidents",
      "Incident Command dashboard",
      "Field Reports",
    ]) {
      expect(optionLabels(withoutIc.wrapper)).not.toContain(absent);
    }

    withoutIc.wrapper.unmount();

    installClientSession(sessionWith(["incidents.view"]), "network");
    selectSessionDepartment(DEPARTMENT_ID);

    const withIc = await openPalette();
    const labels = optionLabels(withIc.wrapper);

    expect(labels).toContain("Incidents");
    expect(labels).toContain("Incident Command dashboard");
    expect(labels).toContain("Field Reports");
    // The author's own workspace is a different page and is not IMS.
    expect(labels).toContain("My Field Reports");

    withIc.wrapper.unmount();
  });

  it("carries no camp and no map place, at the fullest standing in the session", async () => {
    /*
     * MAP-018: "Camps and map locations shall not be added to the global command
     * palette for MVP." The palette builds destinations from the navigation
     * derivation and from nothing else, so the assertion is over everything the
     * fullest seeded role can reach — a camp could only appear here by somebody
     * putting one in navigation, and this is what would catch that.
     *
     * Map search stays in the scoped panel on the map surface (MAP-019).
     */
    const { wrapper } = await openPalette();
    const labels = optionLabels(wrapper);
    const rows = wrapper
      .findAll(".command-palette__result")
      .map((option) => option.text().toLowerCase());

    expect(labels.length).toBeGreaterThan(0);

    for (const label of labels) {
      expect(label.toLowerCase()).not.toMatch(/camp|map/);
    }

    // And nothing describes itself as one either, which is what would catch a
    // camp arriving under a name that does not say so. Deployments are not map
    // places: they are the department's own SLB-009 locations, which is why the
    // assertion is over camps rather than over the word "place".
    for (const row of rows) {
      expect(row).not.toContain("camp");
    }

    wrapper.unmount();
  });

  it("offers no palette at all to a client that has resolved no session", async () => {
    // CLIENT-005 at its limit: not a reduced list, no control. A trigger that
    // opens an empty box is worse than no trigger.
    clearClientSession();

    const { wrapper } = mountShell();
    await flushPromises();

    expect(wrapper.find(".app-shell__command-palette").exists()).toBe(false);

    document.dispatchEvent(
      new KeyboardEvent("keydown", { key: "k", ctrlKey: true }),
    );
    await flushPromises();

    expect(wrapper.find(".command-palette").exists()).toBe(false);

    wrapper.unmount();
  });

  it("groups results by type, with actions after the destinations", async () => {
    const { wrapper } = await openPalette();

    const groups = wrapper
      .findAll(".command-palette__group-label")
      .map((label) => label.text());

    expect(groups[0]).toBe("You");
    expect(groups).toContain("Workflows");
    expect(groups.at(-1)).toBe("Actions");

    // Switching department is an action; a page is not.
    const actions = wrapper
      .findAll('.command-palette__result[data-result-type="action"]')
      .map((option) => option.text());
    expect(actions.join(" ")).toContain("Switch to Gate");

    wrapper.unmount();
  });

  it("offers no department switch to a user with one department", async () => {
    installClientSession(sessionWith(["department.administer"]), "network");
    selectSessionDepartment(DEPARTMENT_ID);

    const { wrapper } = await openPalette();

    expect(
      wrapper.findAll('.command-palette__result[data-result-type="action"]'),
    ).toHaveLength(0);
    expect(wrapper.findAll(".command-palette__group-label").map((l) => l.text()))
      .not.toContain("Actions");

    wrapper.unmount();
  });
});

/*
 * Kiosk guide 11: results limited by trusted workstation state as well as by the
 * signed-in user. A Kiosk holds a workstation rather than a personal session, so
 * its list is built from the machine.
 */
describe("command palette in Kiosk mode", () => {
  const WORKSTATION_ID = "workstation-gate-a";
  const kioskNode = { pinned: true };

  function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
      status,
      headers: { "content-type": "application/json" },
    });
  }

  function stubNode(): void {
    vi.stubGlobal(
      "fetch",
      vi.fn(async (input: RequestInfo | URL) => {
        const path = new URL(String(input)).pathname;

        if (path.startsWith("/api/kiosk/workstations/")) {
          return json({
            shared_workstation: { id: WORKSTATION_ID, name: "Gate A Workstation" },
            pinned: kioskNode.pinned,
            organization: kioskNode.pinned
              ? { id: "org-1", name: "Northwood Collective" }
              : null,
            event: kioskNode.pinned
              ? {
                  id: "event-1",
                  name: "Emberfall 2027",
                  timezone: "UTC",
                  active_event_window_starts_at: null,
                  active_event_window_ends_at: null,
                }
              : null,
            department: null,
            context_pinned_at: kioskNode.pinned ? "2027-05-01T00:00:00+00:00" : null,
            options: [],
          });
        }

        if (path === "/api/auth/shared-workstation-session") {
          return json(
            {
              session_key: "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh",
              session: {
                id: "session-1",
                started_at: "2027-06-01T12:00:00+00:00",
                last_activity_at: "2027-06-01T12:00:00+00:00",
                expires_at: "2027-06-01T12:05:00+00:00",
                inactivity_timeout_seconds: 300,
                reauthenticated_at: null,
              },
              user: { id: "user-1", name: "Dana Reyes" },
              shared_workstation: {
                id: WORKSTATION_ID,
                name: "Gate A Workstation",
                organization_id: "org-1",
                department_id: null,
              },
              event_id: "event-1",
            },
            201,
          );
        }

        return json({ message: "Unstubbed" }, 404);
      }),
    );
  }

  beforeEach(() => {
    kioskNode.pinned = true;
    configureSharedWorkstationId(WORKSTATION_ID);
    stubNode();
    configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
  });

  afterEach(() => {
    configureSharedWorkstationId(null);
    configureMeridianApi(null);
    vi.unstubAllGlobals();
  });

  it("offers setup and the device pages to an unpinned workstation and nothing else", async () => {
    // UI-019, UI-020: a machine that does not know which event it is at has no
    // operational surface to offer, and inferring one from the client session
    // beside it is precisely what the requirement rules out.
    kioskNode.pinned = false;
    await resolveKioskContext();

    const { wrapper } = await openPalette("kiosk");

    expect(optionLabels(wrapper)).toEqual([
      "Workstation setup",
      "Readiness",
      "Health",
    ]);

    wrapper.unmount();
  });

  it("offers only the way in while a pinned workstation is locked", async () => {
    // Kiosk guide 11: results limited by trusted workstation state. Nobody is
    // standing here, so the desk's own work is not something to offer.
    await resolveKioskContext();

    const labels = optionLabels((await openPalette("kiosk")).wrapper);

    expect(labels).toContain("Sign in to this workstation");
    expect(labels).not.toContain("Shift board");
    expect(labels).not.toContain("Switch user");
  });

  it("offers the desk's surfaces once somebody is signed in at it", async () => {
    await resolveKioskContext();
    await enterWorkstationLoginCode({
      sharedWorkstationId: WORKSTATION_ID,
      code: "K3M7PQRS",
    });

    const labels = optionLabels((await openPalette("kiosk")).wrapper);

    expect(labels).toContain("Shift board");
    expect(labels).toContain("Switch user");
    expect(labels).toContain("Workstation home");
    expect(labels).not.toContain("Sign in to this workstation");
  });

  it("carries no organizer or department page into a Kiosk", async () => {
    // Kiosk guide 11: "Admin routes and sensitive records should not appear
    // unless the current user and workstation state allow them." The seeded
    // client session in this file holds every Rangers capability; a Kiosk that
    // read it rather than the workstation would offer all of them.
    await resolveKioskContext();

    const labels = optionLabels((await openPalette("kiosk")).wrapper);

    for (const absent of ["Admin", "Logistics", "Incidents", "Me", "Dashboard"]) {
      expect(labels).not.toContain(absent);
    }
  });
});

describe("command palette behaviour", () => {
  it("opens on the shortcut, closes on Escape, and gives focus back", async () => {
    // Accessibility checklist 11 and 12: focus moves into the dialog on open and
    // returns to the triggering context after dismissal.
    const { wrapper } = mountShell();
    await flushPromises();

    const trigger = wrapper.get(".app-shell__command-palette");
    (trigger.element as HTMLElement).focus();

    document.dispatchEvent(
      new KeyboardEvent("keydown", { key: "k", ctrlKey: true }),
    );
    await flushPromises();

    const input = wrapper.get(".command-palette__input");
    expect(document.activeElement).toBe(input.element);
    expect(trigger.attributes("aria-expanded")).toBe("true");

    await input.trigger("keydown", { key: "Escape" });
    await flushPromises();

    expect(wrapper.find(".command-palette").exists()).toBe(false);
    expect(document.activeElement).toBe(trigger.element);

    wrapper.unmount();
  });

  it("moves through results with the arrows and opens the active one with Enter", async () => {
    const { wrapper, router } = await openPalette();
    const input = wrapper.get(".command-palette__input");

    // The first result is active on open, so one press down lands on the second.
    const labels = optionLabels(wrapper);
    expect(wrapper.get('.command-palette__result[data-active="true"]').text()).toContain(
      labels[0],
    );

    await input.trigger("keydown", { key: "ArrowDown" });
    expect(wrapper.get('.command-palette__result[data-active="true"]').text()).toContain(
      labels[1],
    );

    await input.trigger("keydown", { key: "ArrowUp" });
    await input.trigger("keydown", { key: "ArrowUp" });
    // Wraps rather than stopping, so a long list is reachable from either end.
    expect(wrapper.get('.command-palette__result[data-active="true"]').text()).toContain(
      labels.at(-1),
    );

    await input.trigger("keydown", { key: "Home" });
    await input.trigger("keydown", { key: "Enter" });
    await flushPromises();

    expect(wrapper.find(".command-palette").exists()).toBe(false);
    expect(router.currentRoute.value.name).toBe("staff.me");

    wrapper.unmount();
  });

  it("narrows on what was typed and says so when nothing matches", async () => {
    const { wrapper } = await openPalette();
    const input = wrapper.get(".command-palette__input");

    await input.setValue("logist");
    expect(optionLabels(wrapper)).toEqual(["Logistics"]);

    await input.setValue("nothing here is called this");
    expect(optionLabels(wrapper)).toEqual([]);
    expect(wrapper.get(".command-palette__empty").text()).toContain(
      "nothing here is called this",
    );

    wrapper.unmount();
  });

  it("performs an action rather than navigating", async () => {
    const { wrapper } = await openPalette();
    const input = wrapper.get(".command-palette__input");

    expect(selectedSessionDepartment.value?.departmentId).toBe(
      LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    );

    await input.setValue("switch to gate");
    expect(optionLabels(wrapper)).toEqual(["Switch to Gate"]);

    await wrapper.get(".command-palette__result").trigger("pointerdown");
    await flushPromises();

    expect(selectedSessionDepartment.value?.departmentId).toBe(
      LOCAL_FIELD_DEPARTMENT_IDS.gate,
    );
    expect(wrapper.find(".command-palette").exists()).toBe(false);

    wrapper.unmount();
  });

  it("closes when the backdrop is pressed", async () => {
    const { wrapper } = await openPalette();

    await wrapper.get(".command-palette").trigger("pointerdown");

    expect(wrapper.find(".command-palette").exists()).toBe(false);

    wrapper.unmount();
  });
});

describe("command palette matching", () => {
  const results: CommandPaletteResult[] = [
    result("Logistics", "Roster, attendance, and equipment handoff.", "Workflows"),
    result("Planning", "Coverage across teams and time.", "Workflows"),
    result("Roster", "The department's staff list.", "Department pages"),
    result("Switch to Gate", "Work this event in another department.", "Actions"),
  ];

  function result(
    label: string,
    description: string,
    group: string,
  ): CommandPaletteResult {
    return {
      id: `id:${label}`,
      type: group === "Actions" ? "action" : "navigation",
      group,
      label,
      description,
      shortcut: null,
      to: null,
      run: null,
    };
  }

  it("answers an empty query with everything, because the palette is a directory first", () => {
    expect(matchCommandPaletteResults(results, "   ")).toHaveLength(results.length);
  });

  it("requires every term, in any order", () => {
    expect(
      matchCommandPaletteResults(results, "gate switch").map((entry) => entry.label),
    ).toEqual(["Switch to Gate"]);
    expect(matchCommandPaletteResults(results, "gate planning")).toEqual([]);
  });

  it("matches the description and the group heading as well as the label", () => {
    expect(
      matchCommandPaletteResults(results, "equipment").map((entry) => entry.label),
    ).toEqual(["Logistics"]);
    expect(
      matchCommandPaletteResults(results, "department pages").map((entry) => entry.label),
    ).toEqual(["Roster"]);
  });

  it("puts a page whose name was typed ahead of one that merely mentions it", () => {
    // "Roster" is the Department pages entry's name and the first word of the
    // Logistics description; somebody typing it means the page.
    expect(
      matchCommandPaletteResults(results, "roster").map((entry) => entry.label),
    ).toEqual(["Roster", "Logistics"]);
  });

  it("groups in the order the results arrived, so actions stay last", () => {
    expect(
      groupCommandPaletteResults(results).map((group) => group.group),
    ).toEqual(["Workflows", "Department pages", "Actions"]);
    expect(groupCommandPaletteResults(results)[0]?.results).toHaveLength(2);
  });
});
