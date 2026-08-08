// The dashboard surfaces against a stubbed node (M18.28; UI contract 13.1
// through 13.6; dashboard widget spec 5, 8, 12, 14; CLIENT-005, CLIENT-024).
//
// Four things are asserted here and each is a rule the contract states.
//
//  1. A surface asks the node for its own groups and renders what comes back.
//  2. A quiet widget prints the contract's sentence, carries no list, and does
//     not borrow a reporting card's emphasis (widget spec 14).
//  3. The organizer surface carries no incident data (UI contract 13.4), and a
//     reader with no IC group is told so on the IMS dashboard rather than shown
//     an empty one.
//  4. An action whose destination this client has not built is absent rather
//     than rendered as a dead control (CLIENT-005).
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { orderedWidgets, widgetDestination } from "@/dashboard/dashboardModel";
import {
  kioskCurrentUserWidget,
  kioskNodeStatusWidget,
} from "@/dashboard/kioskDeviceWidgets";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import DepartmentDashboardView from "@/views/DepartmentDashboardView.vue";
import ImsDashboardView from "@/views/ImsDashboardView.vue";
import OrganizerDashboardView from "@/views/OrganizerDashboardView.vue";
import StaffDashboardView from "@/views/StaffDashboardView.vue";

const EVENT_ID = "11111111-1111-4111-8111-111111111111";
const RANGERS_DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

interface WidgetOverrides {
  readonly id: string;
  readonly group: string;
  readonly title: string;
  readonly attention?: string;
  readonly quiet?: boolean;
  readonly quietState?: string;
  readonly summary?: string | null;
  readonly items?: readonly { label: string; detail?: string; status?: string }[];
  readonly metric?: { value: number; label: string } | null;
  readonly actionLabel?: string | null;
  readonly actionSurface?: string | null;
}

function widget(overrides: WidgetOverrides) {
  const quiet = overrides.quiet ?? false;

  return {
    id: overrides.id,
    group: overrides.group,
    title: overrides.title,
    scope: "user/event",
    attention: overrides.attention ?? (quiet ? "routine" : "attention"),
    attention_label: (overrides.attention ?? (quiet ? "routine" : "attention"))
      .replace(/^./, (first) => first.toUpperCase()),
    quiet,
    quiet_state: overrides.quietState ?? "Nothing here",
    summary: quiet ? null : (overrides.summary ?? "Something to report."),
    items: quiet
      ? []
      : (overrides.items ?? []).map((item) => ({
          label: item.label,
          detail: item.detail ?? null,
          status: item.status ?? null,
        })),
    metric: quiet ? null : (overrides.metric ?? null),
    action_label: overrides.actionLabel ?? null,
    action_surface: overrides.actionSurface ?? null,
  };
}

function dashboardPayload(
  groups: readonly { group: string; label: string }[],
  widgets: readonly ReturnType<typeof widget>[],
) {
  return {
    context: {
      event_id: EVENT_ID,
      event_label: "Local Field Event",
      organization_id: "88888888-8888-4888-8888-888888888888",
      department_id: RANGERS_DEPARTMENT_ID,
      department_label: "Rangers",
      time_zone: "America/Los_Angeles",
      as_of: "2026-08-05T18:00:00+00:00",
    },
    groups: groups.map((group) => ({
      ...group,
      contract_section: "13.1",
    })),
    widgets,
    inventory: [
      {
        id: "staff.briefing",
        group: "staff",
        title: "The Briefing",
        permission: "approved event staff",
        quiet_state: "No Briefing items",
        evaluation: "node",
        deferred_to: "M15.6",
      },
    ],
  };
}

/** Every dashboard request this client made, so an over-broad ask is visible. */
function stubDashboard(payload: unknown, status = 200): string[] {
  const requested: string[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);

      if (url.includes("/dashboard")) {
        requested.push(url);

        return new Response(JSON.stringify(payload), {
          status,
          headers: { "content-type": "application/json" },
        });
      }

      return new Response(JSON.stringify({}), {
        status: 200,
        headers: { "content-type": "application/json" },
      });
    }),
  );

  return requested;
}

const mounted: VueWrapper[] = [];

beforeEach(() => {
  clearOfflineReadSet();
  installLocalFieldSession();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  resetSelectedSessionDepartment();
  clearClientSession();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountView(
  component: unknown,
  route: { name: string; params?: Record<string, string> },
): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push(route);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);
  await flushPromises();

  return wrapper;
}

function widgetCard(wrapper: VueWrapper, id: string) {
  return wrapper.find(`[data-widget="${id}"]`);
}

describe("the staff dashboard", () => {
  it("renders what the node reported and prints the contract's quiet sentence", async () => {
    stubDashboard(
      dashboardPayload(
        [{ group: "staff", label: "Staff" }],
        [
          widget({
            id: "staff.current_shift",
            group: "staff",
            title: "Current Shift",
            attention: "attention",
            summary: "Your shift has started and you are not checked in yet.",
            items: [{ label: "Ranger Dirt Day Shift", status: "Not checked in" }],
            actionLabel: "View shift",
            actionSurface: "staff.shifts",
          }),
          widget({
            id: "staff.upcoming_shifts",
            group: "staff",
            title: "Upcoming Shifts",
            quiet: true,
            quietState: "No upcoming shifts",
            actionLabel: "View my shifts",
            actionSurface: "staff.shifts",
          }),
        ],
      ),
    );

    const wrapper = await mountView(StaffDashboardView, { name: "staff.dashboard" });

    const current = widgetCard(wrapper, "staff.current_shift");
    expect(current.exists()).toBe(true);
    expect(current.text()).toContain(
      "Your shift has started and you are not checked in yet.",
    );
    // Attention travels as a word, never as colour alone (widget spec 5).
    expect(current.text()).toContain("Attention");
    expect(current.attributes("data-attention")).toBe("attention");

    const upcoming = widgetCard(wrapper, "staff.upcoming_shifts");
    expect(upcoming.text()).toContain("No upcoming shifts");
    expect(upcoming.classes()).toContain("widget--quiet");
    // A quiet widget carries no list and does not name an attention level.
    expect(upcoming.findAll("li")).toHaveLength(0);
    expect(upcoming.text()).not.toContain("Routine");
  });

  it("names the widgets this node cannot answer yet rather than leaving a gap", async () => {
    stubDashboard(
      dashboardPayload([{ group: "staff", label: "Staff" }], [
        widget({
          id: "staff.quiet_state",
          group: "staff",
          title: "Nothing Needs Action",
          attention: "routine",
          summary: "Nothing needs your attention right now.",
        }),
      ]),
    );

    const wrapper = await mountView(StaffDashboardView, { name: "staff.dashboard" });

    expect(wrapper.text()).toContain("Not yet on this dashboard");
    expect(wrapper.text()).toContain("The Briefing (M15.6)");
  });
});

describe("the department dashboard", () => {
  it("renders both contract groups, each under its own heading", async () => {
    selectSessionDepartment(RANGERS_DEPARTMENT_ID);

    const requested = stubDashboard(
      dashboardPayload(
        [
          { group: "department_lead", label: "Department Lead" },
          { group: "department_operations", label: "Department Operations" },
        ],
        [
          widget({
            id: "dept.coverage_issues",
            group: "department_lead",
            title: "Coverage Issues",
            attention: "warning",
            summary: "1 shift short of 1 staff member.",
            actionLabel: "Open shifts",
            actionSurface: "department.shifts",
          }),
          widget({
            id: "shift.late_missing",
            group: "department_operations",
            title: "Late or Missing Staff",
            quiet: true,
            quietState: "No late or missing staff",
          }),
        ],
      ),
    );

    const wrapper = await mountView(DepartmentDashboardView, {
      name: "events.departments.show",
      params: { eventId: EVENT_ID, departmentId: RANGERS_DEPARTMENT_ID },
    });

    // The department narrows the read, so the request names it.
    expect(requested[0]).toContain(`department_id=${RANGERS_DEPARTMENT_ID}`);

    expect(wrapper.text()).toContain("Department Lead");
    expect(wrapper.text()).toContain("Department Operations");
    expect(widgetCard(wrapper, "dept.coverage_issues").exists()).toBe(true);
    expect(widgetCard(wrapper, "shift.late_missing").text()).toContain(
      "No late or missing staff",
    );
  });
});

describe("the organizer dashboard", () => {
  /**
   * UI contract 13.4: organizer widgets must not surface IMS incidents,
   * restricted Field Reports, incident counts, or incident priority alerts
   * without IC authority.
   *
   * The surface keeps that by asking for the organizer group alone. Even handed
   * a payload carrying an IC widget — which the node would not send — nothing
   * from 13.5 renders here.
   */
  it("asks for the organizer group alone and renders no incident data", async () => {
    const requested = stubDashboard(
      dashboardPayload(
        [
          { group: "organizer", label: "Organizer" },
          { group: "ic", label: "Incident Command" },
        ],
        [
          widget({
            id: "org.application_review",
            group: "organizer",
            title: "Applications to Review",
            summary: "2 applications awaiting review.",
            metric: { value: 2, label: "awaiting review" },
            actionLabel: "Review applications",
            actionSurface: "organizer.applications",
          }),
          widget({
            id: "ic.active_incidents",
            group: "ic",
            title: "Active Incidents",
            attention: "critical",
            summary: "2 incidents open, 1 at Critical.",
            items: [{ label: "Structure fire at Gate 3", status: "Critical" }],
          }),
        ],
      ),
    );

    const wrapper = await mountView(OrganizerDashboardView, {
      name: "organizer.dashboard",
    });

    expect(requested).toHaveLength(1);
    expect(widgetCard(wrapper, "org.application_review").exists()).toBe(true);

    expect(widgetCard(wrapper, "ic.active_incidents").exists()).toBe(false);
    expect(wrapper.text()).not.toContain("Structure fire at Gate 3");
    expect(wrapper.text()).not.toContain("Active Incidents");
    expect(wrapper.text()).not.toContain("Critical");
  });
});

describe("the IMS dashboard", () => {
  it("explains the standing it needs rather than rendering an empty page", async () => {
    stubDashboard(dashboardPayload([{ group: "organizer", label: "Organizer" }], []));

    const wrapper = await mountView(ImsDashboardView, { name: "ims.dashboard" });

    expect(wrapper.text()).toContain(
      "requires IC Viewer, IC Operator, or IC Lead standing",
    );
    expect(wrapper.findAll("[data-widget]")).toHaveLength(0);
    // A reader who may not read the dashboard is not told what is missing from
    // it: that describes a page they are not looking at.
    expect(wrapper.text()).not.toContain("Not yet on this dashboard");
  });

  it("renders the IC group for a reader who holds it", async () => {
    stubDashboard(
      dashboardPayload([{ group: "ic", label: "Incident Command" }], [
        widget({
          id: "ic.serious_incidents",
          group: "ic",
          title: "Serious Incidents",
          attention: "critical",
          summary: "1 incident at Serious or Critical.",
          items: [{ label: "Structure fire at Gate 3", status: "Critical" }],
          actionLabel: "Review incidents",
          actionSurface: "ims.incidents",
        }),
      ]),
    );

    const wrapper = await mountView(ImsDashboardView, { name: "ims.dashboard" });

    const card = widgetCard(wrapper, "ic.serious_incidents");
    expect(card.attributes("data-attention")).toBe("critical");
    // The IMS priority is reported inside the card, beside — not as — the
    // dashboard attention level. Both words are on screen and they differ.
    expect(card.text()).toContain("Critical");
    expect(card.text()).toContain("Structure fire at Gate 3");
    expect(card.find("a").text()).toBe("Review incidents");
  });
});

describe("widget actions", () => {
  it("is absent when this client has no surface for the destination", async () => {
    stubDashboard(
      dashboardPayload([{ group: "staff", label: "Staff" }], [
        widget({
          id: "staff.assigned_departments",
          group: "staff",
          title: "Assigned Departments",
          summary: "You are an active member of 1 department.",
          // `context.departments` is M18.29 and has no route here yet.
          actionLabel: "View departments",
          actionSurface: "context.departments",
        }),
      ]),
    );

    const wrapper = await mountView(StaffDashboardView, { name: "staff.dashboard" });

    const card = widgetCard(wrapper, "staff.assigned_departments");
    expect(card.exists()).toBe(true);
    // The reading is still worth having; the dead control is not (CLIENT-005).
    expect(card.text()).toContain("You are an active member of 1 department.");
    expect(card.findAll("a")).toHaveLength(0);
  });

  it("resolves a department destination only when both ids are known", () => {
    const withDepartment = {
      eventId: EVENT_ID,
      eventLabel: null,
      organizationId: null,
      departmentId: RANGERS_DEPARTMENT_ID,
      departmentLabel: null,
      timeZone: "UTC",
      asOf: "2026-08-05T18:00:00+00:00",
    };

    expect(widgetDestination("department.logistics", withDepartment)).toEqual({
      name: "events.departments.logistics",
      params: { eventId: EVENT_ID, departmentId: RANGERS_DEPARTMENT_ID },
    });

    // Without a department there is no link to build, and guessing one would
    // send a lead into whichever department the router last held.
    expect(
      widgetDestination("department.logistics", {
        ...withDepartment,
        departmentId: null,
      }),
    ).toBeNull();
  });
});

describe("the priority feed", () => {
  it("orders by attention and keeps the contract's order within a level", () => {
    const widgets = [
      widget({ id: "a", group: "staff", title: "A", attention: "routine" }),
      widget({ id: "b", group: "staff", title: "B", attention: "critical" }),
      widget({ id: "c", group: "staff", title: "C", attention: "routine" }),
      widget({ id: "d", group: "staff", title: "D", attention: "warning" }),
      widget({ id: "e", group: "ic", title: "E", attention: "critical" }),
    ].map((payload) => ({
      id: payload.id,
      group: payload.group as "staff" | "ic",
      title: payload.title,
      scope: "",
      attention: payload.attention as "routine" | "critical" | "warning",
      attentionLabel: "",
      quiet: false,
      quietState: "",
      summary: null,
      items: [],
      metric: null,
      actionLabel: null,
      actionSurface: null,
    }));

    expect(orderedWidgets(widgets).map((entry) => entry.id)).toEqual([
      "b",
      "e",
      "d",
      "a",
      "c",
    ]);

    // Narrowing to a group keeps the same rule.
    expect(orderedWidgets(widgets, "staff").map((entry) => entry.id)).toEqual([
      "b",
      "d",
      "a",
      "c",
    ]);
  });
});

describe("the kiosk widgets this device answers", () => {
  it("goes quiet exactly when the local node is reachable", () => {
    const reachable = kioskNodeStatusWidget({
      label: "Node connected",
      meaning: "Central or expected sync target reachable.",
      tone: "connected",
    });

    expect(reachable.quiet).toBe(true);
    expect(reachable.quietState).toBe("Local node reachable");

    const failing = kioskNodeStatusWidget({
      label: "Node connection failing",
      meaning: "This device has a network and its node is not answering.",
      tone: "failing",
    });

    expect(failing.quiet).toBe(false);
    expect(failing.attention).toBe("warning");
    expect(failing.summary).toContain("not answering");
  });

  /**
   * Technical spec 13.3 requires the active user to be shown prominently on a
   * shared workstation, so this card never goes quiet while somebody is signed
   * in — a quiet state here would remove the one thing it exists to state.
   */
  it("always states who is signed in at the workstation", () => {
    const card = kioskCurrentUserWidget("Vera Checked-In", "Gate Kiosk 1");

    expect(card.quiet).toBe(false);
    expect(card.summary).toContain("Vera Checked-In");
    expect(card.items[0]?.label).toBe("Gate Kiosk 1");
    // `kiosk.switch-user` is M18.32, so the action has no route to resolve to.
    expect(
      widgetDestination(card.actionSurface, {
        eventId: EVENT_ID,
        eventLabel: null,
        organizationId: null,
        departmentId: null,
        departmentLabel: null,
        timeZone: "UTC",
        asOf: "2026-08-05T18:00:00+00:00",
      }),
    ).toBeNull();
  });
});
