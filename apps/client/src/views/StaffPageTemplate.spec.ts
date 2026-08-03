import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/session/localFieldSessionFixture";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  COMBINED_NAVIGATION_MAX_ITEMS,
  useCombinedNavigation,
  useStaffLinks,
  useWorkflowLinks,
} from "@/components/workflowLinks";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import DepartmentShiftListView from "@/views/DepartmentShiftListView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";
import DepartmentTrainingListView from "@/views/DepartmentTrainingListView.vue";
import EventInfoView from "@/views/EventInfoView.vue";
import FieldReportsIndexView from "@/views/FieldReportsIndexView.vue";

/*
 * The event and department these tests work in.
 *
 * Declared here rather than imported from a fixture module (M18.9). They are the
 * ids the local development session document carries, which is what the client
 * under test is holding; a shared constants module would make them look like
 * product data rather than what one test file is standing on.
 */
const LOCAL_EVENT_ID = "11111111-1111-4111-8111-111111111111";


async function mountAt(component: unknown, path: string) {
  const router = createRouter({ history: createWebHistory(), routes });
  await router.push(path);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  });
  await flushPromises();

  return wrapper;
}

function departmentPath(departmentId: string, suffix: string): string {
  return `/events/${LOCAL_EVENT_ID}/departments/${departmentId}/${suffix}`;
}

/**
 * Answer the department trainings read as the node would (M16.16).
 *
 * The training surfaces are bound to their endpoints, so which shell they render
 * is decided by the `access` block on the response rather than by a role the
 * client held. These tests are about the shell and the grid, so they only need
 * the node to answer with one training and a stated authority.
 */
function stubTrainingNode(canManage: boolean): void {
  const body = {
    department_id: LOCAL_FIELD_DEPARTMENT_IDS.gate,
    organization_id: "11111111-1111-4111-8111-111111111111",
    access: { can_manage: canManage, can_record_completions: canManage },
    teams: [],
    department_staff: [],
    trainings: [
      {
        id: "99999999-9999-4999-8999-999999999901",
        organization_id: "11111111-1111-4111-8111-111111111111",
        department_id: LOCAL_FIELD_DEPARTMENT_IDS.gate,
        team_id: null,
        team_name: null,
        event_id: null,
        event_name: null,
        name: "Gate Shift Briefing",
        description: null,
        expires_after_days: null,
        delivery: "in_person",
        online_url: null,
        requires_scheduled_attendance: true,
        scheduled_start_at: "2026-08-20T17:00:00+00:00",
        scheduled_end_at: "2026-08-20T18:00:00+00:00",
        location: "Gate Shade",
        capacity: 12,
        time_commitment: null,
        after_training: null,
        provisions: null,
        archived_at: null,
        active_signup_count: 0,
        linked_shift: null,
        unlocked_shifts: [],
        prerequisites: [],
        viewer: {
          can_manage: canManage,
          can_record_completions: canManage,
          is_signed_up: false,
          completion: null,
        },
      },
    ],
  };

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

/**
 * Answer the department shifts read as the node would (M16.18).
 *
 * Same shape of decision as the trainings stub above: the shift surfaces are
 * bound to their endpoint, so which shell they render follows the `access` block
 * on the response rather than a role the client held. One shift is enough for
 * the card list and the table both.
 */
function stubShiftNode(departmentId: string, canManage: boolean): void {
  const teamId = "77777777-7777-4777-8777-777777777771";
  const body = {
    department_id: departmentId,
    department: {
      id: departmentId,
      organization_id: "11111111-1111-4111-8111-111111111111",
      name: "Gate",
      code: "GATE",
      archived_at: null,
    },
    access: {
      can_administer: canManage,
      can_manage: canManage,
      manageable_team_ids: canManage ? [teamId] : [],
    },
    teams: canManage
      ? [{ id: teamId, name: "Credentials", code: "CRED", is_default: false }]
      : [],
    events: [],
    training_options: [],
    waiver_options: [],
    shifts: [
      {
        id: "55555555-5555-4555-8555-555555555551",
        event_id: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        event_name: "Signal Camp 2026",
        department_id: departmentId,
        eligible_team_id: teamId,
        eligible_team_name: "Credentials",
        title: "Credential Check",
        starts_at: "2027-07-01T15:00:00+00:00",
        ends_at: "2027-07-01T21:00:00+00:00",
        capacity: 3,
        active_assignment_count: 1,
        signup_opens_at: null,
        signup_closes_at: null,
        schedule_lock_at: null,
        cancelled_at: null,
        has_started: false,
        can_manage: canManage,
        required_training_ids: [],
        required_waiver_ids: [],
        created_at: "2026-07-01T12:00:00+00:00",
        updated_at: "2026-07-01T12:00:00+00:00",
      },
    ],
  };

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

/**
 * Answer the Event Info read as the node would (M16.19).
 *
 * The six sections are the node's assembly, so this file — which is about the
 * shell and the hero layout, not about the guidance — answers with all six
 * empty.
 */
function stubEventInfoNode(): void {
  const body = {
    event: {
      id: LOCAL_EVENT_ID,
      organization_id: "88888888-8888-4888-8888-888888888888",
      name: "Signal Camp 2026",
      timezone: "UTC",
      starts_at: "2026-08-20T15:00:00+00:00",
      ends_at: "2026-08-24T15:00:00+00:00",
      status: "published",
    },
    section_order: [
      "directions",
      "arrival",
      "packing",
      "food",
      "housing",
      "requirements",
    ],
    sections: [
      "directions",
      "arrival",
      "packing",
      "food",
      "housing",
      "requirements",
    ].map((section) => ({
      section,
      label: section,
      documents: [],
      empty_description: `No published document covers ${section} yet.`,
    })),
  };

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

/**
 * Answer the document library read as the node would (M16.19).
 *
 * `can_maintain` decides which shell the page wears, so a reader is answered
 * with no maintainable scopes and one published document to fill a card.
 */
function stubDocumentLibraryNode(): void {
  const body = {
    organization_id: "88888888-8888-4888-8888-888888888888",
    access: { can_maintain: false, scopes: [] },
    event_info_sections: [],
    documents: [
      {
        id: "44444444-4444-4444-8444-444444444401",
        document_type: "policy",
        organization_id: "88888888-8888-4888-8888-888888888888",
        scope_type: "organization",
        scope_id: "88888888-8888-4888-8888-888888888888",
        scope_label: "Organization: Northwood Collective",
        title: "Volunteer Conduct",
        slug: "volunteer-conduct",
        event_info_section: null,
        event_info_section_label: null,
        markdown_source: "Treat people with care.",
        rendered_html: "<p>Treat people with care.</p>",
        state: "published",
        state_label: "Published",
        version: "1.00",
        published_at: "2026-07-01T16:00:00+00:00",
        archived_at: null,
        updated_at: "2026-07-01T16:00:00+00:00",
        fragment_references: [],
        visibility_summary: "Published to staff in this organization.",
        export_formats: ["markdown", "pdf"],
        can_maintain: false,
      },
    ],
    fragments: [],
  };

  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

// Navigation follows the session response (M16.6).
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
  resetSelectedSessionDepartment();
  clearClientSession();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("staff page template", () => {
  it("renders My Field Reports on the narrow touch-first shell", async () => {
    const wrapper = await mountAt(FieldReportsIndexView, "/staff/field-reports");

    expect(wrapper.find(".staff-page").exists()).toBe(true);
    expect(wrapper.find(".workflow-page").exists()).toBe(false);
    expect(wrapper.find("table").exists()).toBe(false);
  });

  it("renders Event Info on the narrow touch-first shell", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubEventInfoNode();
    const wrapper = await mountAt(
      EventInfoView,
      `/events/${LOCAL_EVENT_ID}/info`,
    );

    expect(wrapper.find(".staff-page").exists()).toBe(true);
    expect(wrapper.find(".workflow-page").exists()).toBe(false);
  });

  it("gives a member the shift card list and a lead the shift table", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);
    stubShiftNode(LOCAL_FIELD_DEPARTMENT_IDS.gate, false);
    const member = await mountAt(
      DepartmentShiftListView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.gate, "shifts"),
    );

    expect(member.find(".staff-page").exists()).toBe(true);
    expect(member.find("table").exists()).toBe(false);
    expect(member.text()).toContain("Shifts your teams are eligible for.");
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubShiftNode(LOCAL_FIELD_DEPARTMENT_IDS.rangers, true);
    const lead = await mountAt(
      DepartmentShiftListView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.rangers, "shifts"),
    );

    expect(lead.find(".workflow-page").exists()).toBe(true);
    expect(lead.find(".staff-page").exists()).toBe(false);
    expect(lead.find("table").exists()).toBe(true);
    expect(lead.text()).toContain("Create shift");
  });

  it("gives a member the training card list and a manager the training table", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);
    stubTrainingNode(false);
    const member = await mountAt(
      DepartmentTrainingListView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.gate, "trainings"),
    );

    expect(member.find(".staff-page").exists()).toBe(true);
    expect(member.find("table").exists()).toBe(false);
    expect(member.text()).not.toContain("New training");
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubTrainingNode(true);
    const manager = await mountAt(
      DepartmentTrainingListView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.rangers, "trainings"),
    );

    expect(manager.find(".workflow-page").exists()).toBe(true);
    expect(manager.find(".staff-page").exists()).toBe(false);
    expect(manager.find("table").exists()).toBe(true);
    expect(manager.text()).toContain("New training");
  });

  it("surrounds the Event Info summary with its section cards", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    stubEventInfoNode();
    const wrapper = await mountAt(
      EventInfoView,
      `/events/${LOCAL_EVENT_ID}/info`,
    );

    const layout = wrapper.get(".hero-center");
    // The summary is a peer of the cards, not a header above them, so it can
    // take the middle column once there is room either side of it.
    expect(layout.get(".hero-center__hero .event-info__hero").text()).toContain(
      "At a glance",
    );
    expect(layout.findAll(".event-info__section").length).toBe(6);
  });

  it("tiles reader lists on the department staff surfaces", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);
    stubDocumentLibraryNode();

    const documents = await mountAt(
      DocumentLibraryView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.gate, "documents"),
    );
    expect(documents.get(".content-grid").classes()).toContain(
      "content-grid--wide",
    );

    stubShiftNode(LOCAL_FIELD_DEPARTMENT_IDS.gate, false);
    const shifts = await mountAt(
      DepartmentShiftListView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.gate, "shifts"),
    );
    expect(shifts.get(".content-grid").classes()).toContain(
      "content-grid--tile",
    );

    stubTrainingNode(false);
    const trainings = await mountAt(
      DepartmentTrainingListView,
      departmentPath(LOCAL_FIELD_DEPARTMENT_IDS.gate, "trainings"),
    );
    expect(trainings.get(".content-grid").classes()).toContain(
      "content-grid--wide",
    );
  });

  it("keeps staff card actions at a thumb-sized target", async () => {
    const wrapper = await mountAt(FieldReportsIndexView, "/staff/field-reports");

    // The whole card title block is the tap target when the card links out.
    expect(wrapper.find(".staff-page__actions").exists()).toBe(true);
    expect(
      wrapper.get(".staff-page__actions a").attributes("data-variant"),
    ).toBe("primary");
  });
});

describe("combined staff and workflow navigation", () => {
  it("combines the menus exactly while the list stays under the threshold", () => {
    // No fixture role reaches the threshold today: the fullest, a Rangers
    // department lead, is ten items against a limit of eleven. The split path
    // is therefore asserted as a rule rather than driven through a fixture, so
    // raising or lowering the limit keeps this honest.
    for (const departmentId of [
      LOCAL_FIELD_DEPARTMENT_IDS.gate,
      LOCAL_FIELD_DEPARTMENT_IDS.dpw,
      LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    ]) {
      selectSessionDepartment(departmentId);
      const navigation = useCombinedNavigation();

      expect(navigation.value.combined).toBe(
        navigation.value.links.length < COMBINED_NAVIGATION_MAX_ITEMS,
      );
      expect(navigation.value.combined).toBe(true);
    }
  });

  it("puts Event Info next to Me whenever the interface is locked to an event", () => {
    for (const departmentId of [
      LOCAL_FIELD_DEPARTMENT_IDS.gate,
      LOCAL_FIELD_DEPARTMENT_IDS.dpw,
      LOCAL_FIELD_DEPARTMENT_IDS.rangers,
    ]) {
      selectSessionDepartment(departmentId);
      const staffLinks = useStaffLinks();

      expect(staffLinks.value.slice(0, 2).map((link) => link.label)).toEqual([
        "Me",
        "Event Info",
      ]);
      expect(staffLinks.value[1]!.to.name).toBe("events.info");
    }
  });

  it("keeps Me out of the workflow hubs", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
    const workflowLinks = useWorkflowLinks();

    expect(workflowLinks.value.map((link) => link.label)).not.toContain("Me");
    expect(workflowLinks.value.map((link) => link.label)).not.toContain(
      "Event Info",
    );
  });
});
