// The organizer staff surface against a stubbed node (M16.14; CLIENT-023,
// CLIENT-024; VOL-001 through VOL-006; data/API 10.6).
//
// The old tests added a person to an array and asserted the array had grown.
// They passed against a client that could not have staffed an event, because
// nothing they exercised ever reached the node that holds the roster.
//
// These stub `fetch` and answer with the payloads the staff endpoints publish.
// The intake tests assert the request body in particular: `invite` is compared
// by identity on the server, `department_id` changes what standing intake
// produces (VOL-002, VOL-003), and both are things a screen can get wrong in a
// way no local model would have noticed.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type RouteLocationRaw } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_FIELD_DEPARTMENT_IDS } from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import HomeView from "@/views/HomeView.vue";
import OrganizerStaffView from "@/views/OrganizerStaffView.vue";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;
const RANGERS_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const DPW_ID = LOCAL_FIELD_DEPARTMENT_IDS.dpw;
const GATE_ID = LOCAL_FIELD_DEPARTMENT_IDS.gate;
const VERA_ID = "33333333-3333-4333-8333-333333333334";
const JORDAN_ID = "33333333-3333-4333-8333-333333333335";

const STAFF_PATH = `/api/organizations/${ORGANIZATION_ID}/staff`;
const DEPARTMENTS_PATH = `/api/organizations/${ORGANIZATION_ID}/departments`;

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

interface NodeReply {
  readonly status?: number;
  readonly body: unknown;
}

/** Answer as the node would, and record what was asked. */
function stubNode(reply: (call: NodeCall) => NodeReply): readonly NodeCall[] {
  const calls: NodeCall[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const call: NodeCall = {
        url: String(input),
        method: init?.method ?? "GET",
        body:
          typeof init?.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null,
      };

      calls.push(call);

      const answer = reply(call);

      return new Response(JSON.stringify(answer.body), {
        status: answer.status ?? 200,
        headers: { "content-type": "application/json" },
      });
    }),
  );

  return calls;
}

function stubUnreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

/** A staff payload in the shape `OrganizerStaffPayload::staff()` sends. */
function staffPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: VERA_ID,
    legal_name: "Vera Okonkwo",
    preferred_name: "Vera",
    display_name: "Vera",
    handle: "vera-radio",
    email: "vera@example.test",
    phone: null,
    city: null,
    state: null,
    organization_status: "active",
    invited: false,
    departments: [],
    lead_department_ids: [],
    archived_at: null,
    ...overrides,
  };
}

function departmentPayload(
  id: string,
  name: string,
  code: string,
): Record<string, unknown> {
  return {
    id,
    organization_id: ORGANIZATION_ID,
    name,
    code,
    description: null,
    default_team_id: null,
    archived_at: null,
    created_at: "2026-07-01T00:00:00+00:00",
    updated_at: "2026-07-01T00:00:00+00:00",
  };
}

/** The two active departments intake and lead selection may name. */
function activeDepartments(): Record<string, unknown> {
  return {
    organization_id: ORGANIZATION_ID,
    departments: [
      departmentPayload(DPW_ID, "DPW", "DPW"),
      departmentPayload(RANGERS_ID, "Rangers", "RANGERS"),
    ],
  };
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down.
 *
 * This screen watches the session, so one left mounted keeps reacting after its
 * test ends: the teardown clears the session, the next `beforeEach` installs it
 * again, and the old component reads that as a context switch and re-reads the
 * roster — against the next test's stub, into the next test's recorded calls.
 */
const mounted: VueWrapper[] = [];

beforeEach(() => {
  /*
   * Reads are durable from M18.9 (technical spec 9.3), so a successful read in
   * one case would be served to the next one from the store. Cleared between
   * cases, and the unreachable-node cases below are about a device that is
   * holding nothing.
   */
  clearReadCache();
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
});

function actAsOrganizer(): void {
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
}

/** Mount a routed surface at `to`, and let its first read settle. */
async function mountAt(
  component: unknown,
  to: RouteLocationRaw,
): Promise<VueWrapper> {
  const router = buildRouter();

  await router.push(to);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

async function mountStaff(): Promise<VueWrapper> {
  return mountAt(OrganizerStaffView, { name: "organizer.staff.index" });
}

describe("organizer staff administration", () => {
  it("reads the roster and the departments intake may name", async () => {
    actAsOrganizer();

    const calls = stubNode((call) =>
      call.url.includes("/departments")
        ? { body: activeDepartments() }
        : {
            body: {
              organization_id: ORGANIZATION_ID,
              staff: [
                staffPayload({
                  departments: [
                    {
                      department_id: RANGERS_ID,
                      department_name: "Rangers",
                      status: "active",
                      is_lead: false,
                    },
                  ],
                }),
              ],
            },
          },
    );

    const wrapper = await mountStaff();

    expect(calls.map((call) => call.url).sort()).toEqual([
      // Only the active departments: an archived one cannot receive intake.
      `http://node.test${DEPARTMENTS_PATH}?status=active`,
      `http://node.test${STAFF_PATH}`,
    ]);

    const row = wrapper.findAll("tbody tr")[0]!.findAll("td");

    expect(row[0]!.text()).toContain("Vera");
    expect(row[1]!.text()).toBe("vera@example.test");
    expect(row[3]!.text()).toBe("Rangers");

    // The selectable departments are the node's, in the node's order.
    expect(
      wrapper
        .findAll("select")[0]!
        .findAll("option")
        .map((option) => option.text()),
    ).toEqual(["No department yet", "DPW", "Rangers"]);
  });

  it("sends intake as the command expects and re-reads the roster", async () => {
    actAsOrganizer();

    let added = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/add-organization-staff")) {
        added = true;

        return {
          status: 201,
          body: staffPayload({
            id: JORDAN_ID,
            legal_name: "Jordan Intake",
            preferred_name: "Jordan",
            display_name: "Jordan",
            handle: "jordan-radio",
            email: "jordan@example.test",
            invited: true,
            departments: [
              {
                department_id: DPW_ID,
                department_name: "DPW",
                status: "active",
                is_lead: false,
              },
            ],
          }),
        };
      }

      if (call.url.includes("/departments")) {
        return { body: activeDepartments() };
      }

      return {
        body: {
          organization_id: ORGANIZATION_ID,
          staff: added
            ? [
                staffPayload({
                  id: JORDAN_ID,
                  display_name: "Jordan",
                  handle: "jordan-radio",
                  email: "jordan@example.test",
                  invited: true,
                  departments: [
                    {
                      department_id: DPW_ID,
                      department_name: "DPW",
                      status: "active",
                      is_lead: false,
                    },
                  ],
                }),
              ]
            : [],
        },
      };
    });

    const wrapper = await mountStaff();
    const inputs = wrapper.findAll("input");

    await inputs[0]!.setValue("Jordan Intake");
    await inputs[1]!.setValue("Jordan");
    await inputs[2]!.setValue("jordan-radio");
    await inputs[3]!.setValue("JORDAN@example.test");
    await wrapper.findAll("select")[0]!.setValue(DPW_ID);
    await wrapper.findAll("form")[0]!.trigger("submit");
    await flushPromises();

    const intake = calls.find((call) =>
      call.url.endsWith("/commands/add-organization-staff"),
    );

    expect(intake?.method).toBe("POST");
    // `invite` as a JSON boolean: the server compares it by identity, so a
    // truthy string would validate and then quietly not invite.
    expect(intake?.body).toEqual({
      organization_id: ORGANIZATION_ID,
      legal_name: "Jordan Intake",
      preferred_name: "Jordan",
      handle: "jordan-radio",
      email: "JORDAN@example.test",
      department_id: DPW_ID,
      invite: true,
    });

    // Standing and invitation are read off the response, not derived here: the
    // server is what promotes a prospective record on intake (VOL-002).
    expect(wrapper.get(".org-staff__success").text()).toBe(
      "Jordan added to staff.",
    );

    const row = wrapper.findAll("tbody tr")[0]!.findAll("td");

    expect(row[1]!.text()).toBe("jordan@example.test");
    expect(row[2]!.text()).toContain("active");
    expect(row[2]!.text()).toContain("invited");
    expect(row[3]!.text()).toBe("DPW");
  });

  it("omits the department when intake names none", async () => {
    actAsOrganizer();

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/add-organization-staff")) {
        return { status: 201, body: staffPayload({ id: JORDAN_ID }) };
      }

      return call.url.includes("/departments")
        ? { body: activeDepartments() }
        : { body: { organization_id: ORGANIZATION_ID, staff: [] } };
    });

    const wrapper = await mountStaff();
    const inputs = wrapper.findAll("input");

    await inputs[0]!.setValue("Jordan Intake");
    await inputs[3]!.setValue("jordan@example.test");
    await wrapper.findAll("form")[0]!.trigger("submit");
    await flushPromises();

    const intake = calls.find((call) =>
      call.url.endsWith("/commands/add-organization-staff"),
    );

    // Absent rather than empty: the field is a nullable uuid, and `""` fails
    // validation where omitting it is the documented way to add nobody to a
    // department yet (VOL-001).
    expect(intake?.body).not.toHaveProperty("department_id");
    expect(intake?.body).toMatchObject({
      preferred_name: null,
      handle: null,
    });
  });

  it("shows the sentence the node refused intake with", async () => {
    actAsOrganizer();

    stubNode((call) => {
      if (call.url.endsWith("/commands/add-organization-staff")) {
        return {
          status: 422,
          body: { message: "This staff member is already in the organization." },
        };
      }

      return call.url.includes("/departments")
        ? { body: activeDepartments() }
        : { body: { organization_id: ORGANIZATION_ID, staff: [] } };
    });

    const wrapper = await mountStaff();
    const inputs = wrapper.findAll("input");

    await inputs[0]!.setValue("Vera Okonkwo");
    await inputs[3]!.setValue("vera@example.test");
    await wrapper.findAll("form")[0]!.trigger("submit");
    await flushPromises();

    expect(wrapper.get(".org-staff__error").text()).toBe(
      "This staff member is already in the organization.",
    );
  });

  it("selects and removes a department lead through the command endpoints", async () => {
    actAsOrganizer();

    let leadDepartmentIds: string[] = [];

    const leadMembership = {
      id: "99999999-9999-4999-8999-999999999901",
      team_id: "77777777-7777-4777-8777-777777777779",
      team_name: "Rangers Leads",
      membership_role: "lead",
      archived_at: null,
    };

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/select-department-lead")) {
        leadDepartmentIds = [RANGERS_ID];

        return {
          body: {
            staff: staffPayload({ lead_department_ids: leadDepartmentIds }),
            team_membership: leadMembership,
          },
        };
      }

      if (call.url.endsWith("/commands/remove-department-lead")) {
        leadDepartmentIds = [];

        return {
          body: {
            staff: staffPayload(),
            team_membership: { ...leadMembership, membership_role: "member" },
          },
        };
      }

      if (call.url.includes("/departments")) {
        return { body: activeDepartments() };
      }

      return {
        body: {
          organization_id: ORGANIZATION_ID,
          staff: [
            staffPayload({
              lead_department_ids: leadDepartmentIds,
              departments: [
                {
                  department_id: RANGERS_ID,
                  department_name: "Rangers",
                  status: "active",
                  is_lead: leadDepartmentIds.includes(RANGERS_ID),
                },
              ],
            }),
          ],
        },
      };
    });

    const wrapper = await mountStaff();
    const selects = wrapper.findAll("select");

    await selects[1]!.setValue(VERA_ID);
    await selects[2]!.setValue(RANGERS_ID);
    await wrapper.findAll("form")[1]!.trigger("submit");
    await flushPromises();

    expect(
      calls.find((call) =>
        call.url.endsWith("/commands/select-department-lead"),
      )?.body,
    ).toEqual({ staff_id: VERA_ID, department_id: RANGERS_ID });
    expect(wrapper.get(".org-staff__success").text()).toBe(
      "Vera selected as Rangers lead.",
    );
    expect(wrapper.findAll("tbody tr")[0]!.findAll("td")[3]!.text()).toBe(
      "Rangers lead",
    );

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Remove lead")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.find((call) =>
        call.url.endsWith("/commands/remove-department-lead"),
      )?.body,
    ).toEqual({ staff_id: VERA_ID, department_id: RANGERS_ID });
    expect(wrapper.get(".org-staff__success").text()).toBe(
      "Vera removed from lead selection.",
    );
    expect(wrapper.findAll("tbody tr")[0]!.findAll("td")[4]!.text()).toBe(
      "None",
    );
  });

  it("reports an unreachable node rather than an empty roster", async () => {
    actAsOrganizer();
    stubUnreachableNode();

    const wrapper = await mountStaff();

    expect(wrapper.get(".org-staff__error").text()).toBe(
      "Unable to load staff. Check the connection to this node and try again.",
    );
    expect(wrapper.text()).not.toContain("No staff in this organization yet.");
  });

  it("restricts the surface where the organization capability is not held, and asks the node for nothing", async () => {
    // Gate is ordinary staff in the seeded session: no `organization.*` grant.
    selectSessionDepartment(GATE_ID);

    const calls = stubNode(() => ({
      body: { organization_id: ORGANIZATION_ID, staff: [] },
    }));

    const wrapper = await mountStaff();

    expect(wrapper.text()).toContain(
      "Staff administration requires organizer or lead organizer authority",
    );
    expect(wrapper.text()).not.toContain("Select lead");
    expect(calls).toEqual([]);
  });

  it("offers Staff from Home to an organizer and not to a department team lead", async () => {
    stubNode(() => ({
      body: { organization_id: ORGANIZATION_ID, staff: [] },
    }));

    actAsOrganizer();

    const organizerHome = await mountAt(HomeView, { name: "home" });

    expect(
      organizerHome.findAll(".home__card h3").map((heading) => heading.text()),
    ).toContain("Staff");

    selectSessionDepartment(DPW_ID);

    const leadHome = await mountAt(HomeView, { name: "home" });

    expect(
      leadHome.findAll(".home__card h3").map((heading) => heading.text()),
    ).not.toContain("Staff");
  });
});
