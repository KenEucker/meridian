// The organization configuration surface and its four featuresets — the
// operational settings, the credit policies and their calculation runs, the
// incident type list, and the organization team designations — against a
// stubbed node (M18.14, M18.16, M18.14A, M18.12; ORG-017, ORG-018, ORG-020,
// ORG-021; CREDIT-001 through CREDIT-003; CLIENT-023, CLIENT-024).
//
// This is the surface ORG-018 requires and Meridian never had. Until it existed
// the incident form created a type whenever somebody typed a name it did not
// recognize, and the lifecycle thresholds, grace period, calendar year start,
// and designations could only be set from a tinker session. These tests
// address it through the page, because that is how a person reaches it.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSessionFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import type { SessionRole } from "@/session/sessionDocument";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;
const ORGANIZER_DEPARTMENT = LOCAL_FIELD_DEPARTMENT_IDS.organizer;
const MEDICAL_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01";
const RETIRED_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa02";
const INTAKE_TEAM_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa03";
const WELCOME_TEAM_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa04";
const CREDIT_POLICY_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa05";
const RETIRED_POLICY_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa06";
const SETTLED_EVENT_ID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa07";

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

function configurationPayload(
  governance: Record<string, unknown> = {
    editable: true,
    holds_authority: true,
    frozen_by_event: null,
  },
): Record<string, unknown> {
  return {
    organization_id: ORGANIZATION_ID,
    configuration: {
      active_inactive_threshold_years: 2,
      prospective_inactive_threshold_years: 1,
      calendar_year_start_month: 3,
      calendar_year_start_day: 1,
      hours_correction_grace_period_days: 14,
      default_credit_policy_id: null,
      organizers_department_id: ORGANIZER_DEPARTMENT,
      default_ic_department_id: null,
      default_placement_department_id: null,
    },
    options: {
      departments: [
        { id: ORGANIZER_DEPARTMENT, name: "Organizers" },
        { id: LOCAL_FIELD_DEPARTMENT_IDS.rangers, name: "Rangers" },
      ],
      credit_policies: [{ id: CREDIT_POLICY_ID, name: "Standard credit" }],
    },
    governance,
  };
}

function creditPoliciesPayload(
  governance: Record<string, unknown> = {
    editable: true,
    holds_authority: true,
    frozen_by_event: null,
  },
): Record<string, unknown> {
  return {
    organization_id: ORGANIZATION_ID,
    credit_policies: [
      {
        id: RETIRED_POLICY_ID,
        name: "Retired rate",
        credit_multiplier: "1.000",
        archived: true,
        archived_at: "2026-06-01T00:00:00+00:00",
        is_default: false,
        shift_count: 1,
      },
      {
        id: CREDIT_POLICY_ID,
        name: "Standard credit",
        credit_multiplier: "1.500",
        archived: false,
        archived_at: null,
        is_default: true,
        shift_count: 2,
      },
    ],
    events: [
      {
        id: SETTLED_EVENT_ID,
        name: "Emberfall 2026",
        ends_at: "2026-07-01T16:00:00+00:00",
        grace_closes_at: "2026-07-15T16:00:00+00:00",
        grace_closed: true,
        open_hours_count: 0,
        uncredited_hours_count: 4,
        credited_hours_count: 0,
        can_calculate: true,
      },
    ],
    governance,
  };
}

function designationsPayload(
  staffCoordinator: { team_id: string; team_name: string } | null = null,
): Record<string, unknown> {
  return {
    organization_id: ORGANIZATION_ID,
    organizers_department: { id: ORGANIZER_DEPARTMENT, name: "Organizers" },
    staff_coordinator:
      staffCoordinator === null
        ? null
        : {
            function_code: "staff_coordinator",
            function_label: "Staff Coordinator",
            ...staffCoordinator,
          },
    eligible_teams: [
      { id: INTAKE_TEAM_ID, name: "Intake Desk" },
      { id: WELCOME_TEAM_ID, name: "Welcome Crew" },
    ],
  };
}

/** Answers every featureset read, and accepts every command. */
function stubAdminNode(
  types?: Record<string, unknown>[],
  staffCoordinator: { team_id: string; team_name: string } | null = null,
  configurationGovernance?: Record<string, unknown>,
): NodeCall[] {
  return stubNode((call) => {
    if (
      call.url.includes("/configuration") ||
      call.url.includes("update-organization-configuration")
    ) {
      return { body: configurationPayload(configurationGovernance) };
    }

    if (call.url.includes("calculate-event-credits")) {
      return {
        body: {
          event_id: SETTLED_EVENT_ID,
          entries_created: 4,
          entries_already_calculated: 0,
          hours_without_credit_policy: 0,
          total_hours: "16.00",
          total_credits: "24.00",
        },
      };
    }

    if (call.url.includes("credit-polic")) {
      return call.method === "POST"
        ? { body: { id: CREDIT_POLICY_ID } }
        : { body: creditPoliciesPayload(configurationGovernance) };
    }

    if (call.url.includes("/designations") || call.url.includes("staff-coordinator-team")) {
      return {
        body: designationsPayload(
          call.url.includes("designate-staff-coordinator-team")
            ? { team_id: INTAKE_TEAM_ID, team_name: "Intake Desk" }
            : call.url.includes("remove-staff-coordinator-team")
              ? null
              : staffCoordinator,
        ),
      };
    }

    return call.method === "POST"
      ? { body: { id: MEDICAL_ID } }
      : { body: listPayload(types) };
  });
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
  /*
   * Reads are durable from M18.9 (technical spec 9.3), so a successful read in
   * one case would be served to the next one from the store. Cleared between
   * cases, and the unreachable-node cases below are about a device that is
   * holding nothing.
   */
  clearOfflineReadSet();
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

    expect(
      calls.some(
        (call) =>
          call.url ===
          `http://node.test/api/organizations/${ORGANIZATION_ID}/incident-types`,
      ),
    ).toBe(true);
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

  it("carries the staff coordinator designation featureset (M18.12)", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(
      calls.some((call) =>
        call.url.endsWith(`/api/organizations/${ORGANIZATION_ID}/designations`),
      ),
    ).toBe(true);
    expect(wrapper.text()).toContain("Team designations");
    expect(wrapper.text()).toContain("Staff Coordinator");
    expect(wrapper.text()).toContain("No team designated");
    // Eligible teams are the node's answer: Organizers Department teams only.
    expect(wrapper.text()).toContain("Intake Desk");
  });

  it("designates and removes the staff coordinator team through its commands", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    await wrapper.get("#staff-coordinator-team").setValue(INTAKE_TEAM_ID);
    await wrapper
      .get("form[aria-label='Designate the Staff Coordinator team']")
      .trigger("submit");
    await flushPromises();

    expect(
      commandCalls(calls, "designate-staff-coordinator-team").at(0)?.body,
    ).toEqual({
      organization_id: ORGANIZATION_ID,
      team_id: INTAKE_TEAM_ID,
    });

    // The command answered with the new designation, so the section now shows
    // the designated team and offers its removal.
    expect(wrapper.text()).toContain("Intake Desk");

    await buttonByLabel(
      wrapper,
      "Remove the Staff Coordinator designation",
    ).trigger("click");
    await flushPromises();

    expect(
      commandCalls(calls, "remove-staff-coordinator-team").at(0)?.body,
    ).toEqual({ organization_id: ORGANIZATION_ID });
  });

  it("keeps each featureset behind its own capability", async () => {
    // Incident types only: the page renders, and the designation read is
    // never made, because a person admitted to one featureset is not thereby
    // entitled to the rest.
    installSession(["organization.incident_types.manage"]);
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(wrapper.text()).toContain("Incident types");
    expect(wrapper.text()).not.toContain("Team designations");
    // The settings form is absent — the page lede mentions "Operational
    // settings" either way, so the check is for the controls themselves.
    expect(wrapper.find("#config-grace-days").exists()).toBe(false);
    expect(calls.some((call) => call.url.includes("/designations"))).toBe(false);
    expect(calls.some((call) => call.url.includes("/configuration"))).toBe(false);
    expect(calls.some((call) => call.url.includes("/credit-policies"))).toBe(false);
  });

  it("carries the operational settings featureset (M18.14)", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(
      calls.some((call) =>
        call.url.endsWith(`/api/organizations/${ORGANIZATION_ID}/configuration`),
      ),
    ).toBe(true);
    expect(wrapper.text()).toContain("Operational settings");

    // The node's current values are on the form, not a blank slate.
    expect(
      (wrapper.get("#config-prospective-years").element as HTMLInputElement).value,
    ).toBe("1");
    expect(
      (wrapper.get("#config-grace-days").element as HTMLInputElement).value,
    ).toBe("14");
    expect(
      (wrapper.get("#config-organizers-department").element as HTMLSelectElement)
        .value,
    ).toBe(ORGANIZER_DEPARTMENT);
  });

  it("saves the settings through the update command", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    await wrapper.get("#config-grace-days").setValue("21");
    await wrapper.get("#config-active-years").setValue("3");
    await wrapper
      .get("form[aria-label='Organization operational settings']")
      .trigger("submit");
    await flushPromises();

    const command = commandCalls(calls, "update-organization-configuration").at(0);

    expect(command?.body).toMatchObject({
      organization_id: ORGANIZATION_ID,
      hours_correction_grace_period_days: 21,
      active_inactive_threshold_years: 3,
      prospective_inactive_threshold_years: 1,
      organizers_department_id: ORGANIZER_DEPARTMENT,
    });
    expect(wrapper.text()).toContain("Configuration saved.");
  });

  it("carries the credit policy featureset (M18.16)", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(
      calls.some((call) =>
        call.url.endsWith(
          `/api/organizations/${ORGANIZATION_ID}/credit-policies`,
        ),
      ),
    ).toBe(true);

    // The rates, said in words, with the default and the usage on each row.
    expect(wrapper.text()).toContain("Standard credit");
    expect(wrapper.text()).toContain("1.500 credits per hour");
    expect(wrapper.text()).toContain("Organization default");
    expect(wrapper.text()).toContain("Named by 2 shifts");
    // Archived policies are shown: a shift may still name one (CREDIT-002).
    expect(wrapper.text()).toContain("Retired rate");
    // The settled event is offered for a run with the node's counts.
    expect(wrapper.text()).toContain(
      "4 settled hours record(s) waiting to be credited",
    );
  });

  it("adds a credit policy through its command and re-reads the list", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    const readsBefore = calls.filter(
      (call) => call.method === "GET" && call.url.includes("/credit-policies"),
    ).length;

    await wrapper.get("#credit-policy-name").setValue("Overnight Gate");
    await wrapper.get("#credit-policy-multiplier").setValue("2");
    await wrapper
      .get("form[aria-label='Add a credit policy']")
      .trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "create-credit-policy").at(0)?.body).toEqual({
      organization_id: ORGANIZATION_ID,
      name: "Overnight Gate",
      credit_multiplier: "2",
    });
    expect(
      calls.filter(
        (call) => call.method === "GET" && call.url.includes("/credit-policies"),
      ).length,
    ).toBeGreaterThan(readsBefore);
  });

  it("edits a credit policy in place", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    const editButtons = wrapper
      .findAll("button")
      .filter((button) => button.text() === "Edit");
    await editButtons.at(0)?.trigger("click");

    await wrapper
      .get("input[aria-label='New name for Standard credit']")
      .setValue("Standard credit 2027");
    await wrapper
      .get("input[aria-label='Credits per hour for Standard credit']")
      .setValue("1.75");
    await wrapper
      .get("form[aria-label='Edit Standard credit']")
      .trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "update-credit-policy").at(0)?.body).toEqual({
      credit_policy_id: CREDIT_POLICY_ID,
      name: "Standard credit 2027",
      credit_multiplier: "1.75",
    });
  });

  it("starts a calculation run and reports what it wrote", async () => {
    installSession();
    const calls = stubAdminNode();

    const { wrapper } = await mountAt("/organizer/configuration");

    await buttonByLabel(wrapper, "Calculate credits for Emberfall 2026").trigger(
      "click",
    );
    await flushPromises();

    expect(commandCalls(calls, "calculate-event-credits").at(0)?.body).toEqual({
      event_id: SETTLED_EVENT_ID,
    });
    expect(wrapper.text()).toContain(
      "Emberfall 2026: 4 ledger entries written for 24.00 credits.",
    );
  });

  it("disables credit policy edits, with the node's reason, while governance freezes them", async () => {
    installSession();
    const calls = stubAdminNode(undefined, null, {
      editable: false,
      holds_authority: true,
      frozen_by_event: { id: LOCAL_FIELD_FIXTURE.eventId, name: "Emberfall 2026" },
    });

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(wrapper.text()).toContain(
      "Credit policies are frozen while Emberfall 2026 is inside its active event window",
    );
    expect(
      wrapper.get("#credit-policy-name").attributes("disabled"),
    ).toBeDefined();

    await wrapper
      .get("form[aria-label='Add a credit policy']")
      .trigger("submit");
    await flushPromises();

    // A disabled form submits nothing: the refusal is explained, not tripped.
    expect(commandCalls(calls, "create-credit-policy")).toHaveLength(0);
  });

  it("reads only, with the node's reason, while governance freezes edits", async () => {
    installSession();
    const calls = stubAdminNode(undefined, null, {
      editable: false,
      holds_authority: true,
      frozen_by_event: { id: LOCAL_FIELD_FIXTURE.eventId, name: "Emberfall 2026" },
    });

    const { wrapper } = await mountAt("/organizer/configuration");

    expect(wrapper.text()).toContain(
      "Configuration is frozen while Emberfall 2026 is inside its active event window",
    );
    expect(
      wrapper.get("#config-grace-days").attributes("disabled"),
    ).toBeDefined();

    await wrapper
      .get("form[aria-label='Organization operational settings']")
      .trigger("submit");
    await flushPromises();

    // A disabled form submits nothing: the refusal is explained, not tripped.
    expect(commandCalls(calls, "update-organization-configuration")).toHaveLength(0);
  });

  it("shows the node's refusal when a save is rejected", async () => {
    installSession();
    stubNode((call) => {
      if (call.url.includes("update-organization-configuration")) {
        return {
          status: 422,
          body: {
            message: "The calendar year start needs both a month and a day, or neither.",
          },
        };
      }

      if (call.url.includes("/configuration")) {
        return { body: configurationPayload() };
      }

      if (call.url.includes("/designations")) {
        return { body: designationsPayload() };
      }

      return { body: listPayload() };
    });

    const { wrapper } = await mountAt("/organizer/configuration");

    await wrapper.get("#config-calendar-day").setValue("");
    await wrapper
      .get("form[aria-label='Organization operational settings']")
      .trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "The calendar year start needs both a month and a day, or neither.",
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
