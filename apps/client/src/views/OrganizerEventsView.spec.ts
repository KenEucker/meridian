// Event administration and audit review as product surfaces (M18.29; UI
// contract 12.6 `organizer.events`, `organizer.audit`; ORG-005, ORG-006;
// requirements 2.4; ORG-015).

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError } from "@/api/meridianApi";
import OrganizerAuditView from "@/views/OrganizerAuditView.vue";
import OrganizerEventsView from "@/views/OrganizerEventsView.vue";

const getEventAdministration = vi.fn();
const createEvent = vi.fn();
const updateEvent = vi.fn();

vi.mock("@/organizer-events/eventAdministrationModel", () => ({
  getEventAdministration: (...args: unknown[]) => getEventAdministration(...args),
  createEvent: (...args: unknown[]) => createEvent(...args),
  updateEvent: (...args: unknown[]) => updateEvent(...args),
}));

const getAuditReview = vi.fn();

vi.mock("@/audit/auditReviewModel", () => ({
  getAuditReview: (...args: unknown[]) => getAuditReview(...args),
}));

vi.mock("@/session/sessionContext", () => ({
  sessionOrganizationId: { value: "org-1" },
}));

const emberfall = {
  id: "event-1",
  name: "Emberfall 2026",
  slug: "emberfall-2026",
  timezone: "America/Los_Angeles",
  minimumStaffAge: 18,
  startsAt: "2026-09-01T00:00:00+00:00",
  endsAt: "2026-09-08T00:00:00+00:00",
  activeWindowStartsAt: "2026-08-28T00:00:00+00:00",
  activeWindowEndsAt: "2026-09-11T00:00:00+00:00",
  archived: false,
  icDepartmentId: null,
  effectiveIcDepartment: { id: "dept-1", name: "Rangers", inherited: true },
  icDepartmentOptions: [
    { id: "dept-1", name: "Rangers" },
    { id: "dept-2", name: "Gate" },
  ],
  authority: {
    phase: "preparation",
    authoritativeNode: null,
  },
};

function administration(overrides: Record<string, unknown> = {}) {
  return {
    organizationId: "org-1",
    defaultIcDepartment: { id: "dept-1", name: "Rangers" },
    events: [emberfall],
    ...overrides,
  };
}

describe("organizer.events", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getEventAdministration.mockResolvedValue(administration());
  });

  it("lists the organization's events with both windows kept apart", async () => {
    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    expect(getEventAdministration).toHaveBeenCalledWith("org-1");
    expect(wrapper.text()).toContain("Emberfall 2026");
    // The published dates are what staff are told; the active event window is
    // the operational phase, and the surface names each.
    expect(wrapper.text()).toContain("Published");
    expect(wrapper.text()).toContain("Active window");
  });

  it("says what Incident Command resolves to when the event names no override (ORG-005)", async () => {
    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    expect(wrapper.text()).toContain("Rangers");
    expect(wrapper.text()).toContain("inherited from the organization default");
  });

  it("offers only this event's own departments for Incident Command (ORG-006)", async () => {
    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Edit")!
      .trigger("click");
    await flushPromises();

    const options = wrapper.findAll("select option").map((option) => option.text());

    expect(options).toEqual([
      "Inherit the organization default",
      "Rangers",
      "Gate",
    ]);
  });

  it("explains rather than offering an empty select when nothing participates yet", async () => {
    getEventAdministration.mockResolvedValue(
      administration({
        events: [{ ...emberfall, icDepartmentOptions: [] }],
      }),
    );

    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Edit")!
      .trigger("click");
    await flushPromises();

    expect(wrapper.findAll("select")).toHaveLength(0);
    expect(wrapper.text()).toContain("No department participates in this event yet");
  });

  it("sends an edit as an update carrying the designation", async () => {
    updateEvent.mockResolvedValue(administration());

    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Edit")!
      .trigger("click");
    await flushPromises();

    await wrapper.find("select").setValue("dept-2");
    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(updateEvent).toHaveBeenCalledWith(
      "org-1",
      "event-1",
      expect.objectContaining({
        name: "Emberfall 2026",
        slug: "emberfall-2026",
        ic_department_id: "dept-2",
      }),
    );
  });

  /*
   * A create has no event department assignments yet, so ORG-006 has nothing to
   * admit and the key is left off entirely rather than sent as a null the node
   * would read as "clear the designation".
   */
  it("creates an event without naming an Incident Command Department", async () => {
    createEvent.mockResolvedValue(administration());

    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Create event")!
      .trigger("click");
    await flushPromises();

    await wrapper.findAll(".events__field input")[0]!.setValue("Emberfall 2027");
    await wrapper.findAll(".events__field input")[1]!.setValue("emberfall-2027");
    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(createEvent).toHaveBeenCalledTimes(1);
    expect(createEvent.mock.calls[0]![1]).not.toHaveProperty("ic_department_id");
  });

  /*
   * Technical spec 10.2 and M12.6: during an event's active window the on-site
   * node is authoritative for the event's *records*, and the `events` row is
   * deliberately exempt so the window can be closed from wherever somebody is
   * standing. So the surface names the node and still offers the edit — taking
   * the control away would leave a window nobody could close.
   */
  it("names the authoritative node for a running event and still offers the edit", async () => {
    getEventAdministration.mockResolvedValue(
      administration({
        events: [
          {
            ...emberfall,
            authority: { phase: "active", authoritativeNode: "Ranger HQ" },
          },
        ],
      }),
    );

    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    expect(wrapper.text()).toContain("Ranger HQ");
    expect(wrapper.text()).toContain("which is how the window is closed");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Edit"),
    ).toBe(true);
  });

  it("states the node's refusal when a save is not accepted", async () => {
    updateEvent.mockRejectedValue(
      new MeridianApiError("Unprocessable", 422, {
        message: 'The address "emberfall-2026" already belongs to another event.',
      }),
    );

    const wrapper = mount(OrganizerEventsView);
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Edit")!
      .trigger("click");
    await flushPromises();

    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(wrapper.get(".events__error").text()).toContain("already belongs");
  });
});

describe("organizer.audit", () => {
  const entry = {
    id: "audit-1",
    action: "staff.status_changed",
    entityType: "App\\Models\\StaffOrganizationStatus",
    entityLabel: "Staff organization status",
    entityId: "status-1",
    actorName: "Olive Organizer",
    actorLabel: "Olive Organizer",
    actorUserId: "user-1",
    eventId: null,
    eventName: null,
    departmentId: null,
    departmentName: null,
    reason: "Returned after two seasons away.",
    sourceContext: "api",
    recordedAt: "2026-08-01T00:00:00+00:00",
    changedFields: ["status"],
  };

  function review(overrides: Record<string, unknown> = {}) {
    return {
      organizationId: "org-1",
      entries: [entry],
      pagination: { page: 1, perPage: 50, total: 1, lastPage: 1 },
      actions: ["staff.status_changed", "team.created"],
      entityTypes: ["App\\Models\\StaffOrganizationStatus"],
      ...overrides,
    };
  }

  beforeEach(() => {
    vi.clearAllMocks();
    getAuditReview.mockResolvedValue(review());
  });

  it("attributes each change to who made it, when, and why (requirements 2.4)", async () => {
    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    expect(wrapper.text()).toContain("staff.status_changed");
    expect(wrapper.text()).toContain("Olive Organizer");
    expect(wrapper.text()).toContain("Returned after two seasons away.");
  });

  it("names the fields that changed and shows no values", async () => {
    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    expect(wrapper.text()).toContain("Fields changed");
    expect(wrapper.text()).toContain("status");
  });

  it("names a scheduled job rather than leaving the actor blank", async () => {
    // Worded by the node, so this surface and the God Mode trail cannot
    // disagree about what a row with nobody behind it is called (M18.34).
    getAuditReview.mockResolvedValue(
      review({
        entries: [
          {
            ...entry,
            actorName: null,
            actorLabel: "A scheduled job",
            actorUserId: null,
          },
        ],
      }),
    );

    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    expect(wrapper.text()).toContain("A scheduled job");
  });

  it("says that incident history is not here and where it is read instead (ORG-015)", async () => {
    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Incident and Field Report history is not here",
    );
  });

  it("offers only the filter values the node sent for this caller", async () => {
    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    const actions = wrapper
      .findAll(".audit__filter")[0]!
      .findAll("option")
      .map((option) => option.text());

    expect(actions).toEqual(["Any", "staff.status_changed", "team.created"]);
  });

  it("starts again at the first page when a filter changes", async () => {
    getAuditReview.mockResolvedValue(
      review({ pagination: { page: 2, perPage: 50, total: 120, lastPage: 3 } }),
    );

    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    await wrapper.findAll("button").find((button) => button.text() === "Older")!
      .trigger("click");
    await flushPromises();

    expect(getAuditReview).toHaveBeenLastCalledWith(
      "org-1",
      expect.objectContaining({ page: 3 }),
    );

    await wrapper.findAll(".audit__filter select")[0]!.setValue(
      "team.created",
    );
    await flushPromises();

    expect(getAuditReview).toHaveBeenLastCalledWith(
      "org-1",
      expect.objectContaining({ action: "team.created", page: 1 }),
    );
  });

  it("states the node's refusal rather than rendering an empty record", async () => {
    getAuditReview.mockRejectedValue(
      new MeridianApiError("Forbidden", 403, {
        message: "Only organizers may review this organization's audit record.",
      }),
    );

    const wrapper = mount(OrganizerAuditView);
    await flushPromises();

    expect(wrapper.get(".audit__notice").text()).toContain(
      "Only organizers may review",
    );
  });
});
