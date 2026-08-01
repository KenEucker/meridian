// The department self-administration surfaces against a stubbed node (M16.15;
// CLIENT-023, CLIENT-024, TEAM requirements; data/API 10.6).
//
// These were fixture tests. They installed a role, mounted the Admin page over
// four compiled-in departments, clicked Archive, and asserted that a module
// array had changed. Nothing in them reached an endpoint, so nothing in them
// said whether the screen and the server agreed on a URL, a request body, or a
// response shape — and the authority they exercised was a role string the
// client had decided for itself.
//
// They now stub `fetch` and answer with the payloads `TeamReadController` and
// the team command endpoints publish. Each test therefore asserts two things:
// what the screen asked the node, and what it did with the answer. Authority is
// no longer set up by the test at all — it is a field on the response, which is
// the same answer the commands enforce.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type RouteLocationRaw } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { routes } from "@/router";
import DepartmentTeamEditView from "@/views/DepartmentTeamEditView.vue";
import DepartmentTeamsListView from "@/views/DepartmentTeamsListView.vue";

const EVENT_ID = "44444444-4444-4444-8444-444444444444";
const ORGANIZATION_ID = "11111111-1111-4111-8111-111111111111";
const DEPARTMENT_ID = "22222222-2222-4222-8222-222222222221";
const DEFAULT_TEAM_ID = "77777777-7777-4777-8777-777777777770";
const DIRT_TEAM_ID = "77777777-7777-4777-8777-777777777771";
const CREATED_TEAM_ID = "77777777-7777-4777-8777-777777777799";
const VERA_STAFF_ID = "33333333-3333-4333-8333-333333333331";
const RILEY_STAFF_ID = "33333333-3333-4333-8333-333333333361";

/** One request this client made, as the assertions read it. */
interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

interface NodeReply {
  readonly status?: number;
  readonly body: unknown;
}

/**
 * Answer as the node would, and record what was asked.
 *
 * `reply` sees the URL and the parsed body so a test can vary its answer over
 * the run. Several of these need the read after a write to differ from the read
 * before it, which is exactly the behavior they are there to prove.
 */
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

      // Admin embeds the document library beside its team panels, and that
      // featureset makes its own read (M16.19). These tests are about the team
      // panels, so the library is answered as a reader with nothing published
      // rather than left to parse a teams payload.
      const answer = call.url.includes("/documents")
        ? { body: emptyDocumentLibraryPayload() }
        : reply(call);

      return new Response(JSON.stringify(answer.body), {
        status: answer.status ?? 200,
        headers: { "content-type": "application/json" },
      });
    }),
  );

  return calls;
}

/** The document library read, answered as a reader with nothing published. */
function emptyDocumentLibraryPayload(): Record<string, unknown> {
  return {
    organization_id: ORGANIZATION_ID,
    access: { can_maintain: false, scopes: [] },
    event_info_sections: [],
    documents: [],
    fragments: [],
  };
}

/** A node that cannot be reached at all, as `fetch` reports it. */
function stubUnreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

function teamPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: DIRT_TEAM_ID,
    department_id: DEPARTMENT_ID,
    name: "Dirt",
    code: "DIRT",
    description: "Open playa patrol.",
    is_default: false,
    archived_at: null,
    created_at: "2026-07-01T00:00:00+00:00",
    updated_at: "2026-07-01T00:00:00+00:00",
    ...overrides,
  };
}

function defaultTeamPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return teamPayload({
    id: DEFAULT_TEAM_ID,
    name: "Rangers Default",
    code: "RANGERS_DEFAULT",
    description: null,
    is_default: true,
    ...overrides,
  });
}

/** The `GET /api/departments/{id}/teams` envelope, as the index publishes it. */
function workspacePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    department_id: DEPARTMENT_ID,
    department: {
      id: DEPARTMENT_ID,
      organization_id: ORGANIZATION_ID,
      name: "Rangers",
      code: "RANGERS",
      description: "Field response.",
      default_team_id: DEFAULT_TEAM_ID,
      archived_at: null,
    },
    access: {
      can_administer: true,
      can_view_led_teams: false,
      led_team_ids: [],
    },
    teams: [defaultTeamPayload(), teamPayload()],
    team_staff: [
      {
        staff_id: VERA_STAFF_ID,
        display_name: "Vera Staff",
        handle: "vera",
        team_id: DIRT_TEAM_ID,
        team_name: "Dirt",
        membership_role: null,
      },
    ],
    department_staff: [
      { staff_id: VERA_STAFF_ID, display_name: "Vera Staff", handle: "vera" },
      { staff_id: RILEY_STAFF_ID, display_name: "Riley Reserve", handle: null },
    ],
    ...overrides,
  };
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down. These
 * screens watch the route and re-read when it changes; one left mounted keeps
 * reacting into the next test's stub and the next test's recorded calls.
 */
const mounted: VueWrapper[] = [];

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

async function mountAt(
  component: unknown,
  to: RouteLocationRaw,
): Promise<{ wrapper: VueWrapper; router: ReturnType<typeof buildRouter> }> {
  const router = buildRouter();

  await router.push(to);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return { wrapper, router };
}

async function mountAdmin(
  query: Record<string, string> = {},
): Promise<VueWrapper> {
  const { wrapper } = await mountAt(DepartmentTeamsListView, {
    name: "events.departments.teams.index",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
    query,
  });

  return wrapper;
}

/** The GET the Admin surface makes, ignoring the document library beside it. */
function workspaceReads(calls: readonly NodeCall[]): readonly NodeCall[] {
  return calls.filter(
    (call) => call.method === "GET" && call.url.includes("/teams?"),
  );
}

describe("department self-administration", () => {
  it("renders every Admin panel from one read of the department's teams", async () => {
    const calls = stubNode(() => ({ body: workspacePayload() }));

    const wrapper = await mountAdmin();

    // Four panels, one request. Asking four times would let them disagree about
    // which teams exist.
    expect(workspaceReads(calls)).toHaveLength(1);
    expect(workspaceReads(calls)[0]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/teams?status=all`,
    );

    expect(wrapper.get("#dept-teams-heading").text()).toBe("Admin");
    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("Department details");
    expect(wrapper.text()).toContain("Rangers Default");
    expect(wrapper.text()).toContain("Dirt");
    expect(wrapper.text()).toContain("Vera Staff");
    expect(wrapper.text()).toContain("Riley Reserve");
  });

  it("saves department details through the command and re-reads", async () => {
    let name = "Rangers";

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-department-details")) {
        name = String(call.body?.name);

        return { body: { ...workspacePayload().department as object, name } };
      }

      return {
        body: workspacePayload({
          department: {
            id: DEPARTMENT_ID,
            organization_id: ORGANIZATION_ID,
            name,
            code: "RANGERS",
            description: "Field response.",
            default_team_id: DEFAULT_TEAM_ID,
            archived_at: null,
          },
        }),
      };
    });

    const wrapper = await mountAdmin();

    // Details open read-only; editing organization-visible identity fields is a
    // deliberate act rather than somewhere to land by opening the page.
    expect(wrapper.find(".dept-teams__readout").exists()).toBe(true);
    expect(wrapper.get(".dept-teams__readout").text()).toContain("Rangers");

    await wrapper.get(".dept-teams__edit").trigger("click");
    await wrapper.findAll('input[type="text"]')[0]!.setValue("  Rangers QA  ");
    await wrapper.get(".dept-teams__form").trigger("submit");
    await flushPromises();

    const save = calls.find((call) =>
      call.url.endsWith("/commands/update-department-details"),
    );

    expect(save?.method).toBe("POST");
    expect(save?.body).toEqual({
      department_id: DEPARTMENT_ID,
      name: "Rangers QA",
      code: "RANGERS",
      description: "Field response.",
    });

    // The panel shows the node's answer to a second question, not a local edit.
    expect(workspaceReads(calls)).toHaveLength(2);
    expect(wrapper.get(".dept-teams__readout").text()).toContain("Rangers QA");
  });

  it("archives and restores a team through the commands and re-reads", async () => {
    let archivedAt: string | null = null;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/archive-team")) {
        archivedAt = "2026-07-02T00:00:00+00:00";

        return { body: teamPayload({ archived_at: archivedAt }) };
      }

      if (call.url.endsWith("/commands/restore-team")) {
        archivedAt = null;

        return { body: teamPayload() };
      }

      return {
        body: workspacePayload({
          teams: [defaultTeamPayload(), teamPayload({ archived_at: archivedAt })],
        }),
      };
    });

    const wrapper = await mountAdmin();

    // Scoped to the teams panel: Admin also embeds the document library, which
    // has Archive controls of its own. The default team is offered none, so
    // there is exactly one here.
    const archiveButtons = wrapper
      .findAll(".dept-teams__management button")
      .filter((button) => button.text() === "Archive");
    expect(archiveButtons).toHaveLength(1);

    await archiveButtons[0]!.trigger("click");
    await flushPromises();

    const archive = calls.find((call) =>
      call.url.endsWith("/commands/archive-team"),
    );

    // The department is not sent: the server reads it off the team, which is
    // also what it authorizes against.
    expect(archive?.body).toEqual({ team_id: DIRT_TEAM_ID });
    expect(workspaceReads(calls)).toHaveLength(2);
    expect(wrapper.get(".dept-teams__management").text()).toContain("Archived");

    await wrapper
      .findAll(".dept-teams__management button")
      .find((button) => button.text() === "Restore")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.find((call) => call.url.endsWith("/commands/restore-team"))?.body,
    ).toEqual({ team_id: DIRT_TEAM_ID });
    expect(
      wrapper
        .findAll(".dept-teams__management button")
        .some((button) => button.text() === "Restore"),
    ).toBe(false);
  });

  it("narrows the teams table over the response it already has", async () => {
    const calls = stubNode(() => ({
      body: workspacePayload({
        teams: [
          defaultTeamPayload(),
          teamPayload({ archived_at: "2026-07-02T00:00:00+00:00" }),
        ],
      }),
    }));

    const wrapper = await mountAdmin({ status: "archived" });

    // The whole department is asked for once and the table narrows over it. The
    // assignment form beside the table offers active teams whatever the table
    // is filtered to, so a narrowed read would not serve both.
    expect(workspaceReads(calls)).toHaveLength(1);
    expect(workspaceReads(calls)[0]?.url).toContain("status=all");

    const rows = wrapper.findAll(".dept-teams__management tbody tr");
    expect(rows).toHaveLength(1);
    expect(rows[0]!.text()).toContain("Dirt");
    expect(rows[0]!.text()).not.toContain("Rangers Default");
  });

  it("assigns and removes team staff through the commands and re-reads", async () => {
    let assigned = false;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/assign-staff-to-team")) {
        assigned = true;

        return { body: { staff_id: RILEY_STAFF_ID, team_id: DIRT_TEAM_ID } };
      }

      if (call.url.endsWith("/commands/remove-staff-from-team")) {
        assigned = false;

        return { body: { staff_id: RILEY_STAFF_ID, team_id: DIRT_TEAM_ID } };
      }

      return {
        body: workspacePayload({
          team_staff: assigned
            ? [
                {
                  staff_id: RILEY_STAFF_ID,
                  display_name: "Riley Reserve",
                  handle: null,
                  team_id: DIRT_TEAM_ID,
                  team_name: "Dirt",
                  membership_role: "member",
                },
              ]
            : [],
        }),
      };
    });

    const wrapper = await mountAdmin();

    const selects = wrapper.get(".dept-teams__assign").findAll("select");
    await selects[0]!.setValue(RILEY_STAFF_ID);
    await selects[1]!.setValue(DIRT_TEAM_ID);
    await wrapper.get(".dept-teams__assign").trigger("submit");
    await flushPromises();

    expect(
      calls.find((call) => call.url.endsWith("/commands/assign-staff-to-team"))
        ?.body,
    ).toEqual({ team_id: DIRT_TEAM_ID, staff_id: RILEY_STAFF_ID });
    // The table, not the form beside it: the roster names Riley either way.
    expect(wrapper.get(".dept-teams__staffmgmt tbody").text()).toContain(
      "Riley Reserve",
    );

    await wrapper
      .findAll(".dept-teams__staffmgmt button")
      .find((button) => button.text() === "Remove")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.find((call) =>
        call.url.endsWith("/commands/remove-staff-from-team"),
      )?.body,
    ).toEqual({ team_id: DIRT_TEAM_ID, staff_id: RILEY_STAFF_ID });
    expect(wrapper.get(".dept-teams__staffmgmt tbody").text()).toBe(
      "No staff are listed for your teams.",
    );
  });

  it("designates and removes a team lead through the commands", async () => {
    let role: string | null = null;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/select-team-lead")) {
        role = "lead";

        return { body: { staff_id: VERA_STAFF_ID, membership_role: role } };
      }

      if (call.url.endsWith("/commands/remove-team-lead")) {
        role = "member";

        return { body: { staff_id: VERA_STAFF_ID, membership_role: role } };
      }

      return {
        body: workspacePayload({
          team_staff: [
            {
              staff_id: VERA_STAFF_ID,
              display_name: "Vera Staff",
              handle: "vera",
              team_id: DIRT_TEAM_ID,
              team_name: "Dirt",
              membership_role: role,
            },
          ],
        }),
      };
    });

    const wrapper = await mountAdmin();

    await wrapper
      .findAll(".dept-teams__staffmgmt button")
      .find((button) => button.text() === "Make team lead")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.find((call) => call.url.endsWith("/commands/select-team-lead"))
        ?.body,
    ).toEqual({ team_id: DIRT_TEAM_ID, staff_id: VERA_STAFF_ID });
    // The role on the row is the node's word for it after the re-read.
    expect(wrapper.get(".dept-teams__staffmgmt").text()).toContain("Team lead");

    await wrapper
      .findAll(".dept-teams__staffmgmt button")
      .find((button) => button.text() === "Remove lead")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.find((call) => call.url.endsWith("/commands/remove-team-lead"))
        ?.body,
    ).toEqual({ team_id: DIRT_TEAM_ID, staff_id: VERA_STAFF_ID });
    expect(wrapper.get(".dept-teams__staffmgmt").text()).not.toContain(
      "Team lead",
    );
  });

  it("shows the sentence the node refused a staff command with", async () => {
    stubNode((call) =>
      call.url.endsWith("/commands/select-team-lead")
        ? {
            status: 422,
            body: { message: "Staff member is not assigned to this team." },
          }
        : { body: workspacePayload() },
    );

    const wrapper = await mountAdmin();

    await wrapper
      .findAll(".dept-teams__staffmgmt button")
      .find((button) => button.text() === "Make team lead")!
      .trigger("click");
    await flushPromises();

    expect(wrapper.get(".dept-teams__staffmgmt .dept-teams__error").text()).toBe(
      "Staff member is not assigned to this team.",
    );
  });

  it("shows a team lead their teams and staff, and no department administration", async () => {
    stubNode(() => ({
      body: workspacePayload({
        access: {
          can_administer: false,
          can_view_led_teams: true,
          led_team_ids: [DIRT_TEAM_ID],
        },
        // The node has already narrowed the teams to the ones this caller leads.
        teams: [teamPayload()],
      }),
    }));

    const wrapper = await mountAdmin();

    expect(wrapper.text()).toContain("Team details");
    expect(wrapper.text()).toContain("Dirt");
    expect(wrapper.text()).toContain("Vera Staff");

    // Authority is the node's answer on the response, not a role the client
    // interpreted for itself, and lead designation stays with administration.
    expect(wrapper.text()).not.toContain("Department details");
    expect(wrapper.text()).not.toContain("Create team");
    expect(wrapper.text()).not.toContain("Make team lead");
    expect(wrapper.find(".dept-teams__management").exists()).toBe(false);
  });

  it("shows the node's refusal instead of an Admin page it cannot fill", async () => {
    stubNode(() => ({
      status: 403,
      body: {
        message:
          "You do not have permission to view department administration for this department.",
      },
    }));

    const wrapper = await mountAdmin();

    // One account of who may see this page, kept on the server (CLIENT-006).
    expect(wrapper.get(".dept-teams__error").text()).toBe(
      "You do not have permission to view department administration for this department.",
    );
    expect(wrapper.text()).not.toContain("Department details");
    expect(wrapper.text()).not.toContain("Team staff");
  });

  it("reports an unreachable node rather than a department with no teams", async () => {
    stubUnreachableNode();

    const wrapper = await mountAdmin();

    // Department administration is connected-only work (data/API 7.2), so the
    // honest answer is that this could not be read — not that there is nothing.
    expect(wrapper.get(".dept-teams__error").text()).toBe(
      "Unable to load department administration. Check the connection to this node and try again.",
    );
    expect(wrapper.text()).not.toContain("No teams match this filter.");
  });
});

describe("department team detail", () => {
  it("fills the edit form from the node's copy and saves it back", async () => {
    const calls = stubNode((call) =>
      call.url.endsWith("/commands/update-team")
        ? { body: teamPayload({ name: "Dirt Patrol" }) }
        : {
            body: {
              ...teamPayload(),
              access: { can_administer: true, can_view_led_team: false },
              team_staff: [],
            },
          },
    );

    const { wrapper } = await mountAt(DepartmentTeamEditView, {
      name: "events.departments.teams.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        teamId: DIRT_TEAM_ID,
      },
    });

    expect(calls[0]?.url).toBe(
      `http://node.test/api/departments/${DEPARTMENT_ID}/teams/${DIRT_TEAM_ID}`,
    );

    const inputs = wrapper.findAll('input[type="text"]');
    expect((inputs[0]!.element as HTMLInputElement).value).toBe("Dirt");
    expect((inputs[1]!.element as HTMLInputElement).value).toBe("DIRT");

    await inputs[0]!.setValue("Dirt Patrol");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    // The department is not sent: the server reads it off the team.
    expect(calls[1]?.url).toBe("http://node.test/api/commands/update-team");
    expect(calls[1]?.body).toEqual({
      team_id: DIRT_TEAM_ID,
      name: "Dirt Patrol",
      code: "DIRT",
      description: "Open playa patrol.",
    });
  });

  it("creates a team and opens the one the node made", async () => {
    const calls = stubNode(() => ({
      status: 201,
      body: teamPayload({
        id: CREATED_TEAM_ID,
        name: "Radio Operators",
        code: "RADIO_OPERATORS",
        description: "Radio operators.",
      }),
    }));

    const { wrapper, router } = await mountAt(DepartmentTeamEditView, {
      name: "events.departments.teams.create",
      params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
    });

    const inputs = wrapper.findAll('input[type="text"]');
    await inputs[0]!.setValue("Radio Operators");
    await flushPromises();

    // The code is suggested from the name and stays editable; the server is
    // what decides whether what was submitted is acceptable.
    expect((inputs[1]!.element as HTMLInputElement).value).toBe(
      "RADIO_OPERATORS",
    );

    await wrapper.get("textarea").setValue("Radio operators.");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(calls[0]?.url).toBe("http://node.test/api/commands/create-team");
    expect(calls[0]?.body).toEqual({
      department_id: DEPARTMENT_ID,
      name: "Radio Operators",
      code: "RADIO_OPERATORS",
      description: "Radio operators.",
    });

    // The id the screen navigates to is the node's, not one it invented.
    expect(router.currentRoute.value.name).toBe("events.departments.teams.edit");
    expect(router.currentRoute.value.params.teamId).toBe(CREATED_TEAM_ID);
  });

  it("shows the field the node rejected on a duplicate team code", async () => {
    stubNode(() => ({
      status: 422,
      body: {
        message: "The code field is required.",
        errors: {
          code: ["A team with this code already exists in the department."],
        },
      },
    }));

    const { wrapper } = await mountAt(DepartmentTeamEditView, {
      name: "events.departments.teams.create",
      params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
    });

    await wrapper.findAll('input[type="text"]')[0]!.setValue("Dirt");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    // The field message, not the collapsed summary the envelope leads with.
    expect(wrapper.get(".dept-team-edit__error").text()).toBe(
      "A team with this code already exists in the department.",
    );
  });

  it("refuses to archive the default team in the node's own words", async () => {
    stubNode((call) =>
      call.url.endsWith("/commands/archive-team")
        ? {
            status: 422,
            body: {
              message: "The department default team cannot be archived.",
            },
          }
        : {
            body: {
              ...defaultTeamPayload(),
              access: { can_administer: true, can_view_led_team: false },
              team_staff: [],
            },
          },
    );

    const { wrapper } = await mountAt(DepartmentTeamEditView, {
      name: "events.departments.teams.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        teamId: DEFAULT_TEAM_ID,
      },
    });

    // The form says so ahead of the attempt, and offers no Archive button, but
    // the rule itself is the server's and is not restated as a second copy.
    expect(wrapper.text()).toContain("cannot be archived");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Archive"),
    ).toBe(false);
  });

  it("says so when the node holds no such team for this department", async () => {
    stubNode(() => ({
      status: 404,
      body: { message: "Team not found for this department." },
    }));

    const { wrapper } = await mountAt(DepartmentTeamEditView, {
      name: "events.departments.teams.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        teamId: CREATED_TEAM_ID,
      },
    });

    expect(wrapper.text()).toContain("Team not found for this department.");
    expect(wrapper.find("form").exists()).toBe(false);
  });

  it("does not offer the form to a caller who leads the team but does not administer", async () => {
    stubNode(() => ({
      body: {
        ...teamPayload(),
        access: { can_administer: false, can_view_led_team: true },
        team_staff: [],
      },
    }));

    const { wrapper } = await mountAt(DepartmentTeamEditView, {
      name: "events.departments.teams.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        teamId: DIRT_TEAM_ID,
      },
    });

    // The read may succeed for a team lead while the update command refuses, so
    // the form is shaped by the same answer the command will enforce.
    expect(wrapper.find("form").exists()).toBe(false);
    expect(wrapper.text()).toContain("do not administer this department");
  });
});
