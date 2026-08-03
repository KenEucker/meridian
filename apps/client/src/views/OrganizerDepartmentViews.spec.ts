// The organizer department surfaces against a stubbed node (M16.14; CLIENT-023,
// CLIENT-024, ORG-002; data/API 10.6).
//
// These were fixture tests: they mounted the views over a bundled array and
// asserted that clicking Archive changed it. Nothing they exercised reached an
// endpoint, so nothing they asserted said whether the screens and the server
// agreed on a URL, a request body, or a response shape.
//
// They now stub `fetch` and answer with the payloads the department endpoints
// publish. What each test asserts is therefore in two halves: what the screen
// asked the node for, and what it did with the answer. That is the seam this
// task moved, and the only place a disagreement can hide.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type RouteLocationRaw } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSessionFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import HomeView from "@/views/HomeView.vue";
import OrganizerDepartmentEditView from "@/views/OrganizerDepartmentEditView.vue";
import OrganizerDepartmentListView from "@/views/OrganizerDepartmentListView.vue";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;
const RANGERS_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const GATE_ID = LOCAL_FIELD_DEPARTMENT_IDS.gate;
const CREATED_ID = "22222222-2222-4222-8222-222222222299";

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
 * `reply` is given the URL and the parsed body so a test can vary its answer
 * over the run — the archive test needs the second read of the list to differ
 * from the first, which is exactly the behavior it is there to prove.
 */
function stubNode(
  reply: (call: NodeCall) => NodeReply,
): readonly NodeCall[] {
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

/** A node that cannot be reached at all, as `fetch` reports it. */
function stubUnreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

/** A department payload in the shape `DepartmentPayload::toArray()` sends. */
function departmentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: RANGERS_ID,
    organization_id: ORGANIZATION_ID,
    name: "Rangers",
    code: "RANGERS",
    description: "Field response.",
    default_team_id: "77777777-7777-4777-8777-777777777770",
    archived_at: null,
    created_at: "2026-07-01T00:00:00+00:00",
    updated_at: "2026-07-01T00:00:00+00:00",
    ...overrides,
  };
}

function departmentList(
  departments: readonly Record<string, unknown>[],
): Record<string, unknown> {
  return { organization_id: ORGANIZATION_ID, departments };
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down.
 *
 * These screens watch the session, so one left mounted keeps reacting after its
 * test ends: the teardown clears the session, the next `beforeEach` installs it
 * again, and the old component reads that as a context switch and re-reads its
 * list — against the next test's stub, into the next test's recorded calls.
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

/** Work as the organizer, which is where the organization capability is held. */
function actAsOrganizer(): void {
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
}

/** Mount a routed surface at `to`, and let its first read settle. */
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

async function mountList(
  query: Record<string, string> = {},
): Promise<VueWrapper> {
  const { wrapper } = await mountAt(OrganizerDepartmentListView, {
    name: "organizer.departments.index",
    query,
  });

  return wrapper;
}

describe("organizer department administration", () => {
  it("reads the organization's departments from the node", async () => {
    actAsOrganizer();

    const calls = stubNode(() => ({
      body: departmentList([
        departmentPayload(),
        departmentPayload({ id: GATE_ID, name: "Gate", code: "GATE" }),
      ]),
    }));

    const wrapper = await mountList();

    // The organization comes off the department in hand, not a compiled-in id.
    expect(calls[0]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/departments?status=all`,
    );
    expect(calls[0]?.method).toBe("GET");

    const names = wrapper
      .findAll("tbody tr")
      .map((row) => row.findAll("td")[0]?.text());

    expect(names).toEqual(["Rangers", "Gate"]);
  });

  it("asks the node for the filter the route names", async () => {
    actAsOrganizer();

    const calls = stubNode(() => ({
      body: departmentList([
        departmentPayload({ archived_at: "2026-07-02T00:00:00+00:00" }),
      ]),
    }));

    await mountList({ status: "archived" });

    // The filter is the server's, not a predicate over a local copy.
    expect(calls[0]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/departments?status=archived`,
    );
  });

  it("archives through the command endpoint and re-reads the list", async () => {
    actAsOrganizer();

    let archivedAt: string | null = null;

    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/archive-department")) {
        archivedAt = "2026-07-02T00:00:00+00:00";

        return { body: departmentPayload({ archived_at: archivedAt }) };
      }

      if (call.url.endsWith("/commands/restore-department")) {
        archivedAt = null;

        return { body: departmentPayload() };
      }

      return {
        body: departmentList([
          departmentPayload({ archived_at: archivedAt }),
          departmentPayload({ id: GATE_ID, name: "Gate", code: "GATE" }),
        ]),
      };
    });

    const wrapper = await mountList();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Archive")!
      .trigger("click");
    await flushPromises();

    const archiveCall = calls.find((call) =>
      call.url.endsWith("/commands/archive-department"),
    );

    expect(archiveCall?.method).toBe("POST");
    expect(archiveCall?.body).toEqual({ department_id: RANGERS_ID });

    // The row is re-read rather than patched in place, so what the screen shows
    // is the node's answer to a second question.
    expect(calls.filter((call) => call.method === "GET")).toHaveLength(2);
    expect(
      wrapper.findAll("tbody tr").map((row) => row.findAll("td")[2]?.text()),
    ).toEqual(["Archived", "Active"]);

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Restore")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.find((call) => call.url.endsWith("/commands/restore-department"))
        ?.body,
    ).toEqual({ department_id: RANGERS_ID });
    expect(
      wrapper.findAll("tbody tr").map((row) => row.findAll("td")[2]?.text()),
    ).toEqual(["Active", "Active"]);
  });

  it("shows the sentence the node refused a command with", async () => {
    actAsOrganizer();

    stubNode((call) =>
      call.url.endsWith("/commands/archive-department")
        ? {
            status: 422,
            body: { message: "Department is already archived." },
          }
        : { body: departmentList([departmentPayload()]) },
    );

    const wrapper = await mountList();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Archive")!
      .trigger("click");
    await flushPromises();

    expect(wrapper.get(".org-dept__error").text()).toBe(
      "Department is already archived.",
    );
  });

  it("reports an unreachable node rather than an empty organization", async () => {
    actAsOrganizer();
    stubUnreachableNode();

    const wrapper = await mountList();

    // Department administration is connected-only work (data/API 7.2), so the
    // honest answer is that this could not be read — not that there are none.
    expect(wrapper.get(".org-dept__error").text()).toBe(
      "Unable to load departments. Check the connection to this node and try again.",
    );
    expect(wrapper.text()).not.toContain("No departments match this filter.");
  });

  it("creates a department and opens the one the node made", async () => {
    actAsOrganizer();

    const calls = stubNode(() => ({
      status: 201,
      body: departmentPayload({
        id: CREATED_ID,
        name: "Placement",
        code: "PLACEMENT",
        description: null,
      }),
    }));

    const { wrapper, router } = await mountAt(OrganizerDepartmentEditView, {
      name: "organizer.departments.create",
    });

    const inputs = wrapper.findAll('input[type="text"]');

    await inputs[0]!.setValue("  Placement  ");
    await inputs[1]!.setValue("PLACEMENT ");
    await wrapper.get("textarea").setValue("   ");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(calls[0]?.url).toBe("http://node.test/api/commands/create-department");
    expect(calls[0]?.body).toEqual({
      organization_id: ORGANIZATION_ID,
      name: "Placement",
      code: "PLACEMENT",
      description: null,
    });
    // The id the screen navigates to is the node's, not one it invented.
    expect(router.currentRoute.value.name).toBe("organizer.departments.edit");
    expect(router.currentRoute.value.params.departmentId).toBe(CREATED_ID);
  });

  it("shows the field the node rejected on a duplicate code", async () => {
    actAsOrganizer();

    stubNode(() => ({
      status: 422,
      body: {
        message: "The code field is required.",
        errors: {
          code: [
            "A department with this code already exists in the organization.",
          ],
        },
      },
    }));

    const { wrapper } = await mountAt(OrganizerDepartmentEditView, {
      name: "organizer.departments.create",
    });

    const inputs = wrapper.findAll('input[type="text"]');

    await inputs[0]!.setValue("Rangers");
    await inputs[1]!.setValue("RANGERS");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    // The field message, not the collapsed summary the envelope leads with.
    expect(wrapper.get(".org-dept-edit__error").text()).toBe(
      "A department with this code already exists in the organization.",
    );
  });

  it("fills the edit form from the node's copy and saves it back", async () => {
    actAsOrganizer();

    const calls = stubNode((call) =>
      call.url.endsWith("/commands/update-department")
        ? { body: departmentPayload({ name: "Rangers Field" }) }
        : { body: departmentPayload() },
    );

    const { wrapper } = await mountAt(OrganizerDepartmentEditView, {
      name: "organizer.departments.edit",
      params: { departmentId: RANGERS_ID },
    });

    expect(calls[0]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/departments/${RANGERS_ID}`,
    );

    const inputs = wrapper.findAll('input[type="text"]');

    expect((inputs[0]!.element as HTMLInputElement).value).toBe("Rangers");
    expect((inputs[1]!.element as HTMLInputElement).value).toBe("RANGERS");
    expect((wrapper.get("textarea").element as HTMLTextAreaElement).value).toBe(
      "Field response.",
    );

    await inputs[0]!.setValue("Rangers Field");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    // The organization is not sent: the server reads it off the department.
    expect(calls[1]?.url).toBe("http://node.test/api/commands/update-department");
    expect(calls[1]?.body).toEqual({
      department_id: RANGERS_ID,
      name: "Rangers Field",
      code: "RANGERS",
      description: "Field response.",
    });
  });

  it("says so when the node holds no such department for this organization", async () => {
    actAsOrganizer();

    stubNode(() => ({
      status: 404,
      body: { message: "Department not found for this organization." },
    }));

    const { wrapper } = await mountAt(OrganizerDepartmentEditView, {
      name: "organizer.departments.edit",
      params: { departmentId: CREATED_ID },
    });

    // Kept apart from a transport failure: a dropped connection is not an
    // answer about what exists.
    expect(wrapper.text()).toContain(
      "Department not found for this organization.",
    );
    expect(wrapper.find("form").exists()).toBe(false);
  });

  it("restricts both surfaces where the organization capability is not held, and asks the node for nothing", async () => {
    // Gate is ordinary staff in the seeded session: no `organization.*` grant.
    selectSessionDepartment(GATE_ID);

    const calls = stubNode(() => ({ body: departmentList([]) }));

    const listWrapper = await mountList();

    expect(listWrapper.text()).toContain(
      "Department administration requires organizer or lead organizer authority",
    );
    expect(listWrapper.find("table").exists()).toBe(false);

    const { wrapper: editWrapper } = await mountAt(OrganizerDepartmentEditView, {
      name: "organizer.departments.edit",
      params: { departmentId: RANGERS_ID },
    });

    expect(editWrapper.text()).toContain(
      "Department administration requires organizer or lead organizer authority",
    );
    expect(editWrapper.find("form").exists()).toBe(false);

    // A refused surface makes no request. The server would refuse it too
    // (CLIENT-006), but spending the round trip to be told so is not the point.
    expect(calls).toEqual([]);
  });

  it("keeps organizer department administration off a DPW team lead's Home", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.dpw);
    stubNode(() => ({ body: departmentList([]) }));

    const { wrapper } = await mountAt(HomeView, { name: "home" });

    const cardLabels = wrapper
      .findAll(".home__card h3")
      .map((heading) => heading.text());

    // A designated team lead reaches their own team, and holds neither
    // `department.administer` nor `organization.departments.manage`.
    expect(cardLabels).toContain("Team Overview");
    expect(cardLabels).not.toContain("Departments");
  });
});
