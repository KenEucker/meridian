// The organization configuration surface, and its incident type featureset,
// against a stubbed node (M18.14A; ORG-018, ORG-020; CLIENT-023, CLIENT-024).
//
// This is the surface ORG-018 requires and Meridian never had. Until it existed
// the incident form created a type whenever somebody typed a name it did not
// recognize, which is how an organization's vocabulary came to be whatever had
// been entered into an incident.
//
// The page is a hub: incident types is the featureset it carries today, and the
// rest of ORG-018's values land beside it in M18.14. These tests address it
// through the page, because that is how a person reaches it.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { configureMeridianApi } from "@/api/meridianApi";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
} from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  LOCAL_FIELD_ORGANIZATION_ID,
  installLocalFieldSession,
} from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionRole } from "@/session/sessionDocument";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;
const ORGANIZER_DEPARTMENT = LOCAL_FIELD_DEPARTMENT_IDS.organizer;
const MEDICAL_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01";
const RETIRED_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa02";

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

interface NodeReply {
  readonly status?: number;
  readonly body: unknown;
}

function stubNode(reply: (call: NodeCall) => NodeReply): NodeCall[] {
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

function listPayload(
  types: Record<string, unknown>[] = defaultTypes(),
): Record<string, unknown> {
  return { organization_id: ORGANIZATION_ID, incident_types: types };
}

function defaultTypes(): Record<string, unknown>[] {
  return [
    {
      id: MEDICAL_ID,
      name: "Medical",
      archived: false,
      archived_at: null,
      created_at: "2026-07-01T00:00:00+00:00",
      incident_count: 3,
    },
    {
      id: RETIRED_ID,
      name: "Retired category",
      archived: true,
      archived_at: "2026-07-02T00:00:00+00:00",
      created_at: "2026-07-01T00:00:00+00:00",
      incident_count: 1,
    },
  ];
}

/** Answers the read, and accepts every command. */
function stubAdminNode(types?: Record<string, unknown>[]): NodeCall[] {
  return stubNode((call) =>
    call.method === "POST"
      ? { body: { id: MEDICAL_ID } }
      : { body: listPayload(types) },
  );
}

async function mountAt(path: string) {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push(path);
  await router.isReady();

  const wrapper = mount(App, { global: { plugins: [router] } });

  await flushPromises();

  return { wrapper, router };
}

/** The seeded organizer, or one holding exactly the codes named. */
function installSession(capabilities?: readonly string[]): void {
  installLocalFieldSession(
    capabilities === undefined ? {} : { roles: narrowedRoles(capabilities) },
  );
  selectSessionDepartment(ORGANIZER_DEPARTMENT);
}

function narrowedRoles(capabilities: readonly string[]): SessionRole[] {
  return [
    {
      role_code: "organizer",
      role_name: "Organizer",
      scope_type: "organization",
      organization_id: ORGANIZATION_ID,
      department_id: ORGANIZER_DEPARTMENT,
      team_id: null,
      team_name: null,
      event_id: LOCAL_FIELD_FIXTURE.eventId,
      team_grant_id: null,
      reason: null,
      capabilities: [...capabilities],
    },
  ];
}

function commandCalls(calls: readonly NodeCall[], command: string): NodeCall[] {
  return calls.filter((call) => call.url.includes(`/api/commands/${command}`));
}

function buttonByLabel(wrapper: VueWrapper, label: string) {
  return wrapper.find(`button[aria-label='${label}']`);
}

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
  configureMeridianApi(null);
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("the organization configuration surface", () => {
  it("is reachable and lists the organization's incident types", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(calls[0]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/incident-types`,
    );
    expect(wrapper.text()).toContain("Medical");
    expect(wrapper.text()).toContain("On 3 incidents");
    // Archived types are shown here and nowhere else, because restoring one is
    // half of why a maintainer opens this page.
    expect(wrapper.text()).toContain("Retired category");
    expect(wrapper.text()).toContain("Archived");
  });

  it("is offered in the home directory as one configuration entry", async () => {
    installSession();
    stubAdminNode();

    const { wrapper } = await mountAt("/");

    // One entry for the page rather than one per setting: the values M18.14
    // adds join this page instead of the directory.
    const link = wrapper
      .findAll("a")
      .find((candidate) => candidate.text().includes("Configuration"));

    expect(link?.attributes("href")).toBe("/organizer/configuration");
  });

  it("adds a type through its command and re-reads the list", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    const readsBefore = calls.filter((call) => call.method === "GET").length;

    await wrapper.get("#incident-type-name").setValue("Camp Dispute");
    await wrapper.get("form[aria-label='Add an incident type']").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "create-incident-type").at(0)?.body).toEqual({
      organization_id: ORGANIZATION_ID,
      name: "Camp Dispute",
    });
    expect(calls.filter((call) => call.method === "GET").length).toBeGreaterThan(
      readsBefore,
    );
  });

  it("renames a type in place", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Rename")
      ?.trigger("click");
    await flushPromises();

    await wrapper.get("form[aria-label='Rename Medical'] input").setValue("Medical aid");
    await wrapper.get("form[aria-label='Rename Medical']").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "rename-incident-type").at(0)?.body).toEqual({
      incident_type_id: MEDICAL_ID,
      name: "Medical aid",
    });
  });

  it("archives and restores a type", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    await buttonByLabel(wrapper, "Archive Medical").trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "archive-incident-type").at(0)?.body).toEqual({
      incident_type_id: MEDICAL_ID,
    });

    await buttonByLabel(wrapper, "Restore Retired category").trigger("click");
    await flushPromises();

    expect(commandCalls(calls, "restore-incident-type").at(0)?.body).toEqual({
      incident_type_id: RETIRED_ID,
    });
  });

  it("shows the node's refusal rather than a generic failure", async () => {
    installSession();
    stubNode((call) =>
      call.method === "POST"
        ? {
            status: 422,
            body: {
              message: 'This organization already has an incident type named Medical.',
            },
          }
        : { body: listPayload() },
    );

    const { wrapper } = await mountAt("/organizer/configuration");

    await wrapper.get("#incident-type-name").setValue("Medical");
    await wrapper.get("form[aria-label='Add an incident type']").trigger("submit");
    await flushPromises();

    expect(wrapper.get(".incident-types__error").text()).toBe(
      "This organization already has an incident type named Medical.",
    );
  });

  it("says so when the organization has configured nothing", async () => {
    installSession();
    stubAdminNode([]);

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(wrapper.text()).toContain(
      "Incident Command cannot categorize an incident until one is added.",
    );
  });

  it("is refused, and unlisted, without the capability", async () => {
    installSession(["organization.staff.manage"]);
    const calls = stubAdminNode();

    const directory = await mountAt("/");

    expect(
      directory.wrapper
        .findAll("a")
        .some((candidate) => candidate.text().includes("Configuration")),
    ).toBe(false);

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(wrapper.text()).toContain(
      "Organizer access is required to configure this organization.",
    );
    expect(calls.filter((call) => call.url.includes("/incident-types"))).toHaveLength(
      0,
    );
  });
});
