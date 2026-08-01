import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { nextTick } from "vue";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_DEPARTMENT_OPS_CONTEXT } from "@/department-ops/fixtures";
import { FIXTURE_RANGERS_DEPARTMENT_ID } from "@/department-teams/fixtureDepartmentAccess";
import { commandOutbox } from "@/outbox/commandOutboxRuntime";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";

const EVENT_ID = LOCAL_DEPARTMENT_OPS_CONTEXT.eventId;
const DEPARTMENT_ID = LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId;
const DAY_SHIFT_ID = "99999999-9999-4999-8999-999999999999";
const SWING_SHIFT_ID = "99999999-9999-4999-8999-999999999998";
const DIRT_TEAM_ID = "77777777-7777-4777-8777-777777777771";
const COMMAND_TEAM_ID = "77777777-7777-4777-8777-777777777772";
const AUTHOR_STAFF_ID = "33333333-3333-4333-8333-333333333333";
const VERA_STAFF_ID = "33333333-3333-4333-8333-333333333334";
const ARI_STAFF_ID = "33333333-3333-4333-8333-333333333336";

/** Every POST the page made, in order, as [path, body]. */
let commands: { path: string; body: Record<string, unknown> }[] = [];

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

async function mountAt(path: string) {
  const router = buildRouter();
  await router.push(path);
  await router.isReady();

  const wrapper = mount(App, {
    global: {
      plugins: [router],
    },
  });
  await flushPromises();

  return { wrapper, router };
}

function overviewPath(): string {
  return `/events/${EVENT_ID}/departments/${DEPARTMENT_ID}/overview`;
}

function logisticsPath(): string {
  return `/events/${EVENT_ID}/departments/${DEPARTMENT_ID}/logistics`;
}

function operationsPath(): string {
  return `/events/${EVENT_ID}/departments/${DEPARTMENT_ID}/operations`;
}

function planningPath(): string {
  return `/events/${EVENT_ID}/departments/${DEPARTMENT_ID}/planning`;
}

function homeCardByHeading(wrapper: VueWrapper, heading: string) {
  return wrapper
    .findAll(".home__card")
    .find((item) => item.find("h3").text() === heading);
}

function context() {
  return {
    event_id: EVENT_ID,
    event_label: LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel,
    department_id: DEPARTMENT_ID,
    department_label: "Rangers",
    time_zone: "America/Los_Angeles",
    as_of: "2027-07-04T18:00:00+00:00",
  };
}

function access() {
  return {
    is_department_lead: true,
    can_manage_presence: true,
    can_manage_attendance: true,
    can_manage_equipment: true,
    can_assign_deployments: true,
    can_manage_planning: true,
    can_administer_department: true,
  };
}

function shift(
  shiftId: string,
  title: string,
  lifecycle: string,
  teamId = DIRT_TEAM_ID,
) {
  return {
    shift_id: shiftId,
    title,
    team_id: teamId,
    team_label: teamId === DIRT_TEAM_ID ? "Dirt" : "Command",
    starts_at:
      shiftId === DAY_SHIFT_ID
        ? "2027-07-04T16:00:00+00:00"
        : "2027-07-04T22:00:00+00:00",
    ends_at:
      shiftId === DAY_SHIFT_ID
        ? "2027-07-04T22:00:00+00:00"
        : "2027-07-05T04:00:00+00:00",
    lifecycle,
    capacity: 4,
  };
}

function card(overrides: Record<string, unknown>) {
  return {
    shift_id: DAY_SHIFT_ID,
    title: "Ranger Dirt Day Shift",
    team_id: DIRT_TEAM_ID,
    team_label: "Dirt",
    starts_at: "2027-07-04T16:00:00+00:00",
    ends_at: "2027-07-04T22:00:00+00:00",
    lifecycle: "active",
    attendance_state: null,
    assignment_id: null,
    can_check_in: false,
    can_check_out: false,
    can_mark_no_show: false,
    can_add_to_shift: false,
    add_to_shift_blocked_reason: null,
    ...overrides,
  };
}

function equipment(id: string, name: string, assetTag: string) {
  return {
    checkout_id: null,
    equipment_item_id: id,
    name,
    asset_tag: assetTag,
    status: "available",
    checked_out_at: null,
  };
}

/**
 * SLB-018's half of the off-site block, which the base payload does not carry:
 * the one workspace blocked there is blocked by a check-in. Set for the test
 * that reads the equipment refusal, so the desk still has an unblocked on-site
 * workspace everywhere else.
 */
let ariHoldsEquipment = false;

const AVAILABLE_EQUIPMENT = [
  equipment("equipment-radio-13", "Radio 13", "RDO-13"),
  equipment("equipment-radio-14", "Radio 14", "RDO-14"),
];

function logisticsPayload() {
  return {
    ...{ context: context(), access: access() },
    searchable_staff: [
      {
        staff_id: AUTHOR_STAFF_ID,
        display_name: "Local Field Author",
        handle: "local-field-author",
        team_label: "Dirt",
        presence_state: "on_site",
      },
      {
        staff_id: VERA_STAFF_ID,
        display_name: "Vera Staff",
        handle: "vera",
        team_label: "Dirt",
        presence_state: "off_site",
      },
      {
        staff_id: ARI_STAFF_ID,
        display_name: "Ari Ranger",
        handle: "ari",
        team_label: "Dirt",
        presence_state: "on_site",
      },
    ],
    searchable_equipment: [
      {
        equipment_item_id: "equipment-radio-12",
        name: "Radio 12",
        asset_tag: "RDO-12",
        status: "checked_out",
        status_label: "Checked out",
        holder_staff_id: AUTHOR_STAFF_ID,
        holder_name: "Local Field Author",
      },
      {
        equipment_item_id: "equipment-radio-13",
        name: "Radio 13",
        asset_tag: "RDO-13",
        status: "available",
        status_label: "Available",
        holder_staff_id: null,
        holder_name: null,
      },
    ],
    searchable_shifts: [
      shift(DAY_SHIFT_ID, "Ranger Dirt Day Shift", "active"),
      shift(SWING_SHIFT_ID, "Ranger Dirt Swing Shift", "upcoming"),
    ],
    staff_workspaces: {
      [AUTHOR_STAFF_ID]: {
        staff_id: AUTHOR_STAFF_ID,
        display_name: "Local Field Author",
        handle: "local-field-author",
        team_label: "Dirt",
        presence_state: "on_site",
        can_go_off_site: false,
        off_site_blocked_reason:
          "Staff must be checked out from department shifts before being marked off-site.",
        shift_cards: [
          card({
            attendance_state: "checked_in",
            assignment_id: "assignment-author-day",
            can_check_out: true,
          }),
        ],
        open_equipment: [
          {
            checkout_id: "checkout-radio-12",
            equipment_item_id: "equipment-radio-12",
            name: "Radio 12",
            asset_tag: "RDO-12",
            status: "checked_out",
            checked_out_at: "2027-07-04T16:05:00+00:00",
          },
        ],
        available_equipment: AVAILABLE_EQUIPMENT,
        future_signups: [],
      },
      [VERA_STAFF_ID]: {
        staff_id: VERA_STAFF_ID,
        display_name: "Vera Staff",
        handle: "vera",
        team_label: "Dirt",
        presence_state: "off_site",
        can_go_off_site: true,
        off_site_blocked_reason: null,
        shift_cards: [
          card({
            attendance_state: "scheduled",
            assignment_id: "assignment-vera-day",
            can_mark_no_show: true,
          }),
        ],
        open_equipment: [],
        available_equipment: AVAILABLE_EQUIPMENT,
        future_signups: [],
      },
      [ARI_STAFF_ID]: {
        staff_id: ARI_STAFF_ID,
        display_name: "Ari Ranger",
        handle: "ari",
        team_label: "Dirt",
        presence_state: "on_site",
        can_go_off_site: !ariHoldsEquipment,
        off_site_blocked_reason: ariHoldsEquipment
          ? "Staff must return or resolve checked-out department equipment before being marked off-site."
          : null,
        /*
         * What the node hands an on-site staff member: the shift it will take
         * them onto, and the one it will not, with the reason it will not. The
         * second card is on screen precisely so it can say why it offers
         * nothing (SLB-008).
         */
        shift_cards: [
          card({ can_add_to_shift: true }),
          card({
            shift_id: SWING_SHIFT_ID,
            title: "Ranger Dirt Swing Shift",
            team_id: COMMAND_TEAM_ID,
            team_label: "Command",
            starts_at: "2027-07-04T22:00:00+00:00",
            ends_at: "2027-07-05T04:00:00+00:00",
            lifecycle: "upcoming",
            add_to_shift_blocked_reason:
              "This shift is for the Command team, and they are not a member of it.",
          }),
        ],
        open_equipment: ariHoldsEquipment
          ? [
              {
                checkout_id: "checkout-vest-4",
                equipment_item_id: "equipment-vest-4",
                name: "Vest 4",
                asset_tag: "VST-04",
                status: "checked_out",
                checked_out_at: "2027-07-04T17:20:00+00:00",
              },
            ]
          : [],
        available_equipment: AVAILABLE_EQUIPMENT,
        future_signups: [
          {
            signup_id: "signup-ari-swing",
            shift_id: SWING_SHIFT_ID,
            shift_title: "Ranger Dirt Swing Shift",
            starts_at: "2027-07-04T22:00:00+00:00",
            ends_at: "2027-07-05T04:00:00+00:00",
            state: "signed_up",
          },
        ],
      },
    },
  };
}

function overviewPayload() {
  return {
    context: context(),
    access: access(),
    shifts: [
      shift(DAY_SHIFT_ID, "Ranger Dirt Day Shift", "active"),
      shift(SWING_SHIFT_ID, "Ranger Dirt Swing Shift", "upcoming"),
    ],
    selected_shift_id: DAY_SHIFT_ID,
    exceptions: [
      {
        id: "coverage",
        severity: "warning",
        label: "Coverage gap",
        detail: "Ranger Dirt Day Shift is 2 below its capacity of 4.",
      },
    ],
    assignments: [
      {
        assignment_id: "assignment-author-day",
        staff_id: AUTHOR_STAFF_ID,
        display_name: "Local Field Author",
        handle: "local-field-author",
        team_label: "Dirt",
        attendance_state: "checked_in",
        checked_in_at: "2027-07-04T15:52:00+00:00",
        current_deployment_id: "deployment-gate-1",
        unscheduled: false,
      },
      {
        assignment_id: "assignment-vera-day",
        staff_id: VERA_STAFF_ID,
        display_name: "Vera Staff",
        handle: "vera",
        team_label: "Dirt",
        attendance_state: "scheduled",
        checked_in_at: null,
        current_deployment_id: null,
        unscheduled: false,
      },
    ],
    equipment_out: [
      {
        checkout_id: "checkout-radio-12",
        item_name: "Radio 12",
        asset_tag: "RDO-12",
        staff_name: "Local Field Author",
        checked_out_at: "2027-07-04T16:05:00+00:00",
      },
    ],
    deployments: [
      {
        id: "deployment-gate-1",
        name: "Gate 1",
        description: "Entry checkpoint",
        location_details: "North entry checkpoint",
      },
    ],
    on_site_count: 2,
  };
}

function operationsPayload() {
  return {
    context: context(),
    access: access(),
    deployments: [
      {
        id: "deployment-gate-1",
        name: "Gate 1",
        description: null,
        location_details: null,
      },
      {
        id: "deployment-perimeter",
        name: "Perimeter North",
        description: null,
        location_details: null,
      },
    ],
    rows: [
      {
        assignment_id: "assignment-author-day",
        staff_id: AUTHOR_STAFF_ID,
        display_name: "Local Field Author",
        shift_id: DAY_SHIFT_ID,
        shift_title: "Ranger Dirt Day Shift",
        current_deployment_id: "deployment-gate-1",
        current_deployment_name: "Gate 1",
      },
    ],
    equipment_out_count: 1,
  };
}

function planningRow(
  shiftId: string,
  title: string,
  teamId: string,
  overrides: Record<string, unknown> = {},
) {
  return {
    shift_id: shiftId,
    title,
    team_id: teamId,
    team_label: teamId === DIRT_TEAM_ID ? "Dirt" : "Command",
    starts_at: "2027-07-04T16:00:00+00:00",
    ends_at: "2027-07-04T22:00:00+00:00",
    lifecycle: "active",
    capacity: 4,
    signed_up_or_assigned_count: 3,
    checked_in_count: 2,
    no_show_count: 0,
    unscheduled_count: 0,
    planned_hours: 24,
    actual_hours: 4.2,
    variance_hours: -19.8,
    status_label: "Under target",
    ...overrides,
  };
}

function planningPayload(teamId: string | null) {
  const rows = [
    planningRow(DAY_SHIFT_ID, "Ranger Dirt Day Shift", DIRT_TEAM_ID),
    planningRow(SWING_SHIFT_ID, "Ranger Dirt Swing Shift", DIRT_TEAM_ID, {
      lifecycle: "upcoming",
      status_label: "Upcoming",
    }),
    planningRow("overnight", "Ranger Command Overnight", COMMAND_TEAM_ID, {
      lifecycle: "completed",
      capacity: null,
      status_label: "Completed over plan",
    }),
  ];

  return {
    context: context(),
    access: access(),
    teams: [
      { team_id: DIRT_TEAM_ID, team_label: "Dirt" },
      { team_id: COMMAND_TEAM_ID, team_label: "Command" },
    ],
    filters: { team_id: teamId, date: null },
    rows:
      teamId === null ? rows : rows.filter((row) => row.team_id === teamId),
  };
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

/**
 * A node that answers the four department operations reads and accepts the
 * commands behind them (M16.21).
 *
 * Commands are recorded rather than modelled: what these tests assert is that
 * the surface issued the right command with the right body and read the desk
 * again afterwards, which is what binding means here. What the command did to
 * the department is the server's own tests' subject.
 */
/**
 * A promise the next command waits on before answering.
 *
 * The in-flight state is the subject of one of these tests, and a stub that
 * resolves immediately never has one to observe.
 */
let heldCommand: Promise<void> | null = null;

function holdCommand(held: Promise<void>): void {
  heldCommand = held;
}

function stubDepartmentOpsNode(): void {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input), "http://node.test");

      if (init?.method === "POST") {
        commands.push({
          path: url.pathname,
          body: JSON.parse(String(init.body ?? "{}")) as Record<string, unknown>,
        });

        if (heldCommand !== null) {
          await heldCommand;
        }

        return json({ warnings: [] }, 201);
      }

      if (url.pathname.endsWith("/overview")) {
        return json(overviewPayload());
      }

      if (url.pathname.endsWith("/logistics")) {
        return json(logisticsPayload());
      }

      if (url.pathname.endsWith("/operations")) {
        return json(operationsPayload());
      }

      if (url.pathname.endsWith("/planning")) {
        return json(planningPayload(url.searchParams.get("team_id")));
      }

      if (url.pathname.endsWith("/field-reports")) {
        return json({
          event_id: EVENT_ID,
          field_reports: [1, 2, 3].map((index) => ({
            id: `field-report-${index}`,
            display_number: `FRA-2027-00000${index}`,
            title: `Field Report ${index}`,
            author_name: "Vera Ranger",
            body: "Observed.",
            created_at: "2027-07-04T20:00:00+00:00",
            related_incidents: [],
          })),
        });
      }

      const state = url.searchParams.get("state");
      const priority = url.searchParams.get("priority");
      const total = priority === "Critical" ? 0 : state === "active" ? 2 : 3;

      return json({
        event_id: EVENT_ID,
        filters: {},
        filter_options: {},
        assignable: {},
        pagination: { page: 1, per_page: 1, total, total_pages: 1 },
        presets: [],
        incidents: [],
      });
    }),
  );
}

/** Open a staff member's workspace through the desk's own search. */
async function openWorkspace(
  wrapper: VueWrapper,
  displayName: string,
): Promise<void> {
  await wrapper.get('input[type="search"]').setValue(displayName);

  // Scoped to the search results: the shell's own staff menu carries the signed
  // in user's name too, and clicking that opens a menu rather than a workspace.
  const hit = wrapper
    .get(".entity-search__results")
    .findAll("button")
    .find((button) => button.text().includes(displayName));

  await hit!.trigger("click");
  await flushPromises();
}

// Navigation follows the session response (M16.6), so the home directory has
// nothing in it until one is established.
beforeEach(() => {
  commands = [];
  heldCommand = null;
  ariHoldsEquipment = false;
  commandOutbox.clear();
  installLocalFieldSession();
  selectSessionDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
  stubDepartmentOpsNode();
});

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
  commandOutbox.clear();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("department operations surfaces", () => {
  it("registers workflow routes and home links", async () => {
    const names = routes.map((route) => route.name);
    expect(names).toContain("events.departments.overview");
    expect(names).toContain("events.departments.logistics");
    expect(names).toContain("events.departments.operations");
    expect(names).toContain("events.departments.planning");
    expect(names).toContain("staff.me");
    expect(names).toContain("events.info");
    expect(names).toContain("ims.incidents.index");
    expect(names).toContain("ims.field-reports.index");

    const { wrapper } = await mountAt("/");
    expect(wrapper.get("#home-heading").text()).toBe(
      LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel,
    );
    const eventCard = wrapper.get(".home__event-card");
    expect(eventCard.find(".home__eyebrow").exists()).toBe(false);
    expect(eventCard.text()).not.toContain("Admin");
    expect(eventCard.get(".home__status").text()).toBe("ongoing");
    expect(eventCard.text()).toContain("Operations");
    expect(eventCard.text()).toContain("Location");
    expect(eventCard.text()).toContain("Description");
    expect(eventCard.text()).not.toContain("active");
    expect(wrapper.text()).toContain("Overview");
    expect(wrapper.text()).toContain("Logistics");
    expect(wrapper.text()).toContain("Operations Center");
    expect(wrapper.text()).toContain("Incidents");
    expect(wrapper.text()).toContain("My Field Reports");
    expect(wrapper.text()).toContain("Readiness");
    expect(homeCardByHeading(wrapper, "Incidents")?.attributes("href")).toBe(
      "/ims/incidents",
    );
    expect(
      homeCardByHeading(wrapper, "Field Reports")?.attributes("href"),
    ).toBe("/ims/field-reports");
    expect(homeCardByHeading(wrapper, "Health")?.attributes("href")).toBe(
      "/settings/about",
    );
  });

  it("renders the staff Me page with profile links and current schedule", async () => {
    const { wrapper } = await mountAt("/staff/me");

    expect(wrapper.get("#me-heading").text()).toBe("Local Field Author");
    expect(wrapper.get(".me__nav a").attributes("href")).toBe("/");
    expect(wrapper.get(".me__photo").attributes("aria-label")).toContain(
      "Local Field Author profile photo",
    );
    expect(wrapper.text()).toContain("Years of service");
    expect(wrapper.text()).toContain("Events worked");
    expect(wrapper.text()).toContain("My Field Reports");
    expect(
      wrapper
        .findAll(".me__links a")
        .some((link) => link.attributes("href") === "/staff/field-reports"),
    ).toBe(true);
    expect(wrapper.text()).toContain("Schedule for ongoing event");
    expect(wrapper.get(".me__event").attributes("href")).toBe(overviewPath());
  });

  it("redirects legacy shift-board routes to the new surfaces", async () => {
    const { router } = await mountAt(
      `/events/${EVENT_ID}/departments/${DEPARTMENT_ID}/shift-board/planning`,
    );

    expect(router.currentRoute.value.name).toBe("events.departments.planning");
  });

  it("renders the overview the node answered with, in its documented order", async () => {
    const { wrapper } = await mountAt(overviewPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Department Overview");
    expect(wrapper.get("#exceptions-heading").text()).toBe(
      "Exceptions needing attention",
    );
    expect(wrapper.get("#checked-in-heading").text()).toBe(
      "Checked-in staff currently working",
    );
    expect(wrapper.get("#assignments-heading").text()).toBe("Shift assignments");
    expect(wrapper.get("#equipment-heading").text()).toBe("Equipment out");

    const headingOrder = [
      wrapper.get("#exceptions-heading").element,
      wrapper.get("#checked-in-heading").element,
      wrapper.get("#assignments-heading").element,
      wrapper.get("#equipment-heading").element,
    ];
    expect(
      headingOrder[0]!.compareDocumentPosition(headingOrder[1]!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      headingOrder[1]!.compareDocumentPosition(headingOrder[2]!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      headingOrder[2]!.compareDocumentPosition(headingOrder[3]!) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();

    // The node's exception text and its deployment name, not the client's.
    expect(wrapper.text()).toContain(
      "Ranger Dirt Day Shift is 2 below its capacity of 4.",
    );
    expect(wrapper.text()).toContain("Gate 1");
    expect(wrapper.text()).toContain("Radio 12");
    expect(wrapper.find("label").text()).toContain("Selected shift");
  });

  it("re-reads the overview when a different shift is selected", async () => {
    const fetchMock = globalThis.fetch as unknown as ReturnType<typeof vi.fn>;
    const { wrapper } = await mountAt(overviewPath());

    fetchMock.mockClear();
    await wrapper.get("select").setValue(SWING_SHIFT_ID);
    await flushPromises();

    const requested = fetchMock.mock.calls.map((call) => String(call[0]));
    expect(
      requested.some((url) => url.includes(`shift_id=${SWING_SHIFT_ID}`)),
    ).toBe(true);
  });

  it("opens a staff-first logistics workspace from the node's index", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Logistics Window");
    expect(wrapper.get("#current-shifts-heading").text()).toBe("Current shifts");
    expect(wrapper.text()).toContain("Ranger Dirt Day Shift");
    expect(wrapper.get("#search-scope-heading").text()).toBe("Search scope");
    expect(wrapper.text()).toContain("Find staff");

    await wrapper.get('input[type="search"]').setValue("swing");
    const shiftButton = wrapper
      .findAll("button")
      .find((button) => button.text().includes("Ranger Dirt Swing Shift"));
    expect(shiftButton).toBeTruthy();
    await shiftButton!.trigger("click");

    expect(wrapper.get("#search-context-heading").text()).toBe(
      "Ranger Dirt Swing Shift",
    );

    await openWorkspace(wrapper, "Vera Staff");

    expect(wrapper.get("#staff-workspace-heading").text()).toBe("Vera Staff");
    expect(wrapper.text()).toContain("Mark on-site");
    expect(wrapper.get("#active-shifts-heading").text()).toBe("Active shift");
    expect(wrapper.get("#upcoming-shifts-heading").text()).toBe(
      "Upcoming shifts",
    );
    expect(wrapper.get("#outgoing-shifts-heading").text()).toBe(
      "Outgoing shifts",
    );
    expect(wrapper.text()).toContain("Provisions");
    // The node decided this one, and the card offers exactly what it allowed.
    expect(
      wrapper
        .get(".logistics__workspace")
        .findAll("button")
        .map((button) => button.text()),
    ).toContain("Mark no-show");
  });

  it("sends presence to the node and reads the desk again", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Vera Staff");
    await wrapper
      .get(".logistics__workspace")
      .findAll("button")
      .find((button) => button.text() === "Mark on-site")!
      .trigger("click");
    await flushPromises();

    expect(commands).toHaveLength(1);
    expect(commands[0]!.path).toBe("/api/commands/mark-staff-on-site");
    expect(commands[0]!.body).toMatchObject({
      event_id: EVENT_ID,
      department_id: DEPARTMENT_ID,
      staff_id: VERA_STAFF_ID,
    });
    expect(wrapper.text()).toContain("Vera Staff marked on-site.");
  });

  /*
   * The two off-site blocks are the node's answers, and the desk's job is to
   * state them before somebody presses anything (SLB-017, SLB-018). The button
   * is closed and the sentence is the one the command would have refused with,
   * so the operator reads what to do about it — return the vest — rather than a
   * disabled control with no explanation.
   */
  it("closes the off-site option in the node's words when equipment is still out", async () => {
    ariHoldsEquipment = true;

    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Ari Ranger");

    const panel = wrapper.get(".logistics__workspace");
    const offSite = panel
      .findAll("button")
      .find((button) => button.text() === "Mark off-site");

    expect(offSite!.attributes("disabled")).toBeDefined();
    expect(panel.text()).toContain(
      "Staff must return or resolve checked-out department equipment before being marked off-site.",
    );
    expect(panel.text()).toContain("Vest 4");

    await offSite!.trigger("click");
    await flushPromises();

    expect(commands).toHaveLength(0);
  });

  /*
   * A command here is a write plus a re-read of the whole desk, and on a field
   * network that is long enough for the screen to look like it did nothing.
   */
  it("says which command it is waiting on, and keeps the workspace readable", async () => {
    let release: (() => void) | null = null;

    // Hold the presence command open so the in-flight state can be observed.
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    holdCommand(held);

    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Vera Staff");
    await wrapper
      .get(".logistics__workspace")
      .findAll("button")
      .find((button) => button.text() === "Mark on-site")!
      .trigger("click");
    await nextTick();

    const panel = wrapper.get(".logistics__workspace");
    expect(panel.attributes("aria-busy")).toBe("true");
    expect(panel.get(".logistics__pending").text()).toContain(
      "Marking Vera Staff on-site",
    );
    // Nothing is hidden: an operator waiting on a check-in still needs to read
    // the shift they are checking somebody in for.
    expect(panel.text()).toContain("Ranger Dirt Day Shift");
    // And nothing takes a second press while the first is still running.
    expect(
      panel
        .findAll("button")
        .filter((button) => button.text() === "Mark off-site")
        .every((button) => button.attributes("disabled") !== undefined),
    ).toBe(true);

    release!();
    await flushPromises();

    expect(wrapper.get(".logistics__workspace").attributes("aria-busy")).toBe(
      "false",
    );
    expect(wrapper.find(".logistics__pending").exists()).toBe(false);
  });

  it("says presence, shift, and equipment state in words as well as color", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Vera Staff");

    const pills = wrapper
      .get(".logistics__workspace")
      .findAll(".status-pill");

    // The label is always rendered, so a pill read with no color at all — a
    // bright tent, a color vision difference — says exactly as much.
    expect(pills.length).toBeGreaterThan(0);
    // The visually hidden prefix names what the state is *of*, so a pill read on
    // its own is not an unattached adjective.
    expect(pills.map((pill) => pill.text())).toContain("Presence: Off-site");
    for (const pill of pills) {
      expect(pill.attributes("data-tone")).toBeDefined();
      expect(pill.text().trim()).not.toBe("");
    }
  });

  it("queues a check-out through the command outbox rather than sending it", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Local Field Author");
    await wrapper
      .get(".logistics__workspace")
      .findAll("button")
      .find((button) => button.text() === "Check out")!
      .trigger("click");

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.attributes("aria-modal")).toBe("true");
    expect(dialog.get("#attendance-dialog-heading").text()).toBe("Check out");
    expect(dialog.text()).toContain("Return equipment");
    expect(dialog.text()).toContain("Radio 12");

    // Both actual times are editable during check-out (SLB-006).
    const times = dialog.findAll('input[type="datetime-local"]');
    expect(times).toHaveLength(2);
    await times[0]!.setValue("2027-07-04T15:05");
    await times[1]!.setValue("2027-07-04T09:15");

    await dialog
      .findAll("button")
      .find((button) => button.text() === "Confirm")!
      .trigger("click");
    await flushPromises();

    // Check-out is an Alpha 1 offline write, so it is held rather than posted;
    // the equipment return beside it is connected-only and goes now.
    const queued = commandOutbox.all();
    expect(queued).toHaveLength(1);
    expect(queued[0]!.commandType).toBe("check-out-staff");
    expect(queued[0]!.payload).toMatchObject({
      shift_id: DAY_SHIFT_ID,
      staff_id: AUTHOR_STAFF_ID,
      actual_started_at: new Date("2027-07-04T09:15").toISOString(),
      actual_ended_at: new Date("2027-07-04T15:05").toISOString(),
    });
    expect(queued[0]!.payload.origin_node_id).toBeUndefined();

    expect(commands.map((command) => command.path)).toEqual([
      "/api/commands/return-equipment",
    ]);
    expect(commands[0]!.body).toMatchObject({
      equipment_checkout_id: "checkout-radio-12",
      return_condition: "returned",
    });
  });

  it("hands out equipment from the workspace as a connected-only command", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Ari Ranger");
    await wrapper
      .get(".logistics__workspace")
      .findAll("button")
      .find((button) => button.text() === "Check out equipment")!
      .trigger("click");

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.get("#attendance-dialog-heading").text()).toBe(
      "Check out equipment",
    );
    expect(dialog.text()).toContain("Radio 13");
    await dialog.get('input[value="equipment-radio-13"]').setValue(true);
    await dialog
      .findAll("button")
      .find((button) => button.text() === "Confirm")!
      .trigger("click");
    await flushPromises();

    expect(commands).toHaveLength(1);
    expect(commands[0]!.path).toBe("/api/commands/checkout-equipment");
    expect(commands[0]!.body).toMatchObject({
      equipment_item_id: "equipment-radio-13",
      staff_id: ARI_STAFF_ID,
    });
    expect(commandOutbox.all()).toHaveLength(0);
  });

  it("adds an on-site staff member to a shift the node offered", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Ari Ranger");
    await wrapper
      .get(".logistics__workspace")
      .findAll("button")
      .find((button) => button.text() === "Add to shift")!
      .trigger("click");
    await flushPromises();

    expect(commands).toHaveLength(1);
    expect(commands[0]!.path).toBe("/api/commands/add-staff-to-shift");
    expect(commands[0]!.body).toMatchObject({
      shift_id: DAY_SHIFT_ID,
      staff_id: ARI_STAFF_ID,
    });
  });

  /*
   * The addition is offered where the node offered it and explained where it
   * did not (M18.3; SLB-008). One workspace, two cards: the desk draws a button
   * on the one it was given and prints the node's sentence on the other. It
   * never draws a button it then disables, because a disabled control with no
   * words beside it tells an operator nothing they can act on.
   */
  it("offers the addition only where the node offered it, and says why not", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Ari Ranger");

    const cards = wrapper.get(".logistics__workspace").findAll(".logistics__cards li");
    const offered = cards.find((entry) =>
      entry.text().includes("Ranger Dirt Day Shift"),
    )!;
    const refused = cards.find((entry) =>
      entry.text().includes("Ranger Dirt Swing Shift"),
    )!;

    expect(offered.findAll("button").map((button) => button.text())).toContain(
      "Add to shift",
    );
    expect(offered.find(".logistics__card-blocked").exists()).toBe(false);

    expect(
      refused.findAll("button").map((button) => button.text()),
    ).not.toContain("Add to shift");
    expect(refused.get(".logistics__card-blocked").text()).toBe(
      "This shift is for the Command team, and they are not a member of it.",
    );
  });

  /*
   * On-site presence comes first (requirements 5.8). An off-site staff member
   * gets no unassigned cards from the node at all, so the desk offers no
   * addition anywhere in their workspace — the presence pill and "Mark on-site"
   * are the work to do first, and they are what is on screen.
   */
  it("offers an off-site staff member no shift addition at all", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    await openWorkspace(wrapper, "Vera Staff");

    const panel = wrapper.get(".logistics__workspace");
    expect(panel.text()).toContain("Presence: Off-site");
    expect(panel.findAll("button").map((button) => button.text())).not.toContain(
      "Add to shift",
    );
    expect(commands).toHaveLength(0);
  });

  it("moves a deployment through the node from the Operations Center", async () => {
    const { wrapper } = await mountAt(operationsPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Operations Center");
    expect(wrapper.find("#deployments-heading").exists()).toBe(true);
    expect(wrapper.text()).toContain("Perimeter North");

    const selects = wrapper.findAll(".ops__form select");
    await selects[1]!.setValue("deployment-perimeter");
    await wrapper.get(".ops__form").trigger("submit");
    await flushPromises();

    expect(commands).toHaveLength(1);
    expect(commands[0]!.path).toBe("/api/commands/set-current-deployment");
    expect(commands[0]!.body).toMatchObject({
      shift_id: DAY_SHIFT_ID,
      staff_id: AUTHOR_STAFF_ID,
      deployment_id: "deployment-perimeter",
    });
  });

  it("keeps operations center modules capability-composed", async () => {
    const { wrapper, router } = await mountAt(operationsPath());

    expect(wrapper.text()).toContain("Deployments");
    expect(wrapper.text()).toContain("Field Reports");
    expect(wrapper.find("#field-reports-heading").exists()).toBe(true);
    expect(
      wrapper.findAll("a").some((link) => link.text() === "Submit Field Report"),
    ).toBe(true);
    expect(wrapper.find("#incidents-heading").exists()).toBe(true);
    expect(
      wrapper.findAll("a").some((link) => link.text() === "Open IMS incidents"),
    ).toBe(true);
    await flushPromises();

    const metricCards = wrapper.findAll(".ops__metric-card");
    const incidentCards = metricCards.slice(0, 3);
    expect(incidentCards).toHaveLength(3);
    expect(
      incidentCards.find((card) => card.text().includes("Event total"))?.text(),
    ).toContain("3");
    expect(
      incidentCards.find((card) => card.text().includes("Active"))?.text(),
    ).toContain("2");
    expect(
      incidentCards
        .find((card) => card.text().includes("Critical priority"))
        ?.text(),
    ).toContain("0");
    const fieldReportCards = metricCards.slice(3);
    expect(fieldReportCards).toHaveLength(3);
    expect(
      fieldReportCards.find((card) => card.text().includes("Unlinked"))?.text(),
    ).toContain("3");
    // The page composes shortcuts from capability, so it does not restate the
    // author's personal workspace. That link lives in the shell's staff menu,
    // which is why this assertion reads the page rather than the whole app.
    expect(wrapper.get(".dept-ops").text()).not.toContain("My Field Reports");
    expect(wrapper.text()).not.toContain(
      "Incident overview requires event-scoped Incident Command capability.",
    );

    await incidentCards
      .find((card) => card.text().includes("Critical priority"))!
      .trigger("click");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("ims.incidents.index");
    expect(router.currentRoute.value.query).toMatchObject({
      state: "all",
      priority: "Critical",
    });
  });

  it("renders an identity-free planning table and filters at the node", async () => {
    const { wrapper } = await mountAt(planningPath());

    expect(wrapper.get("#dept-ops-heading").text()).toBe("Planning Table");
    expect(wrapper.text()).toContain("Plan versus actual");
    expect(wrapper.text()).toContain("Signed up / assigned");
    expect(wrapper.text()).toContain("No target");
    expect(wrapper.text()).toContain("Actual hours");
    expect(wrapper.text()).toContain("Variance");
    expect(wrapper.get("#planning-gantt-heading").text()).toBe(
      "Scheduled shifts",
    );
    expect(wrapper.findAll(".planning__gantt-row")).toHaveLength(3);
    expect(wrapper.get("#planning-shift-detail-heading").text()).toBe(
      "Shift detail",
    );
    expect(wrapper.text()).toContain("Aggregate rows remain identity-free");

    // SLB-019: the drill-down is counts, and no name reaches this surface.
    const drilldown = wrapper.get(".planning__drilldown");
    expect(drilldown.text()).toContain("Ranger Dirt Day Shift");
    expect(drilldown.text()).toContain("Unscheduled additions");
    expect(drilldown.text()).not.toContain("Local Field Author");
    expect(drilldown.text()).not.toContain("Vera Staff");

    expect(wrapper.findAll(".planning__table-frame tbody tr")).toHaveLength(3);

    // Narrowing is the node's answer to a narrower question, not a filter over
    // rows already here.
    await wrapper.get("select").setValue(COMMAND_TEAM_ID);
    await flushPromises();

    expect(wrapper.findAll(".planning__table-frame tbody tr")).toHaveLength(1);
    expect(wrapper.text()).toContain("Ranger Command Overnight");
    expect(wrapper.text()).not.toContain("Ranger Dirt Day Shift");
  });

  it("lists staff on shift above the search with the scope notice beside it", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    const roster = wrapper.get(".logistics__on-shift");
    expect(roster.get("#on-shift-heading").text()).toBe("On shift now");
    // Checked in on the day shift; Vera Staff is only scheduled.
    expect(roster.text()).toContain("Local Field Author");
    expect(roster.text()).not.toContain("Vera Staff");
    expect(roster.text()).toContain("Ranger Dirt Day Shift");

    const html = wrapper.html();
    expect(html.indexOf("logistics__on-shift")).toBeLessThan(
      html.indexOf("entity-search"),
    );
    expect(
      wrapper.get(".logistics__find").find(".logistics__cache").exists(),
    ).toBe(true);
    expect(wrapper.find(".logistics__find .entity-search").exists()).toBe(true);
  });

  it("checks a staff member out straight from the on-shift roster", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    const row = wrapper
      .findAll(".logistics__on-shift-list li")
      .find((item) => item.text().includes("Local Field Author"));
    expect(row).toBeTruthy();

    await row!
      .findAll("button")
      .find((button) => button.text() === "Check out")!
      .trigger("click");

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.get("#attendance-dialog-heading").text()).toBe("Check out");
    // Opening from the roster selects that staff member, so the dialog and the
    // workspace act on the same person.
    expect(wrapper.get("#staff-workspace-heading").text()).toBe(
      "Local Field Author",
    );
  });

  it("opens equipment checkout for a roster member without searching first", async () => {
    const { wrapper } = await mountAt(logisticsPath());

    const row = wrapper
      .findAll(".logistics__on-shift-list li")
      .find((item) => item.text().includes("Local Field Author"));

    await row!
      .findAll("button")
      .find((button) => button.text() === "Check out equipment")!
      .trigger("click");

    const dialog = wrapper.get('[role="dialog"]');
    expect(dialog.get("#attendance-dialog-heading").text()).toBe(
      "Check out equipment",
    );
    expect(wrapper.get("#staff-workspace-heading").text()).toBe(
      "Local Field Author",
    );
  });

  it("states an unreachable node rather than an empty department", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountAt(logisticsPath());

    expect(wrapper.get(".logistics__error").text()).toContain(
      "Unable to load this department's logistics desk",
    );
    expect(wrapper.find(".logistics__on-shift-list").exists()).toBe(false);
  });
});
