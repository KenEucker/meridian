// The participation surface, the reviewer's queue, and one application read on
// its own (M18.21A, M18.29; APP-001, APP-003, APP-005, APP-011, APP-016,
// APP-017, APP-018, APP-019).

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError } from "@/api/meridianApi";
import { participationLink } from "@/applications/participationModel";
import OrganizerApplicationDetailView from "@/views/OrganizerApplicationDetailView.vue";
import OrganizerApplicationsView from "@/views/OrganizerApplicationsView.vue";
import ParticipationView from "@/views/ParticipationView.vue";

const routeParams = { organizationSlug: "northwood-collective" } as Record<
  string,
  string
>;

vi.mock("vue-router", () => ({
  useRoute: () => ({ params: routeParams }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const getOrganizationParticipation = vi.fn();
const getEventParticipation = vi.fn();
const submitApplication = vi.fn();
const requestApplicantPortalLink = vi.fn();

vi.mock("@/applications/participationModel", async () => {
  const actual = await vi.importActual<
    typeof import("@/applications/participationModel")
  >("@/applications/participationModel");

  return {
    ...actual,
    getOrganizationParticipation: (...args: unknown[]) =>
      getOrganizationParticipation(...args),
    getEventParticipation: (...args: unknown[]) =>
      getEventParticipation(...args),
    submitApplication: (...args: unknown[]) => submitApplication(...args),
    requestApplicantPortalLink: (...args: unknown[]) =>
      requestApplicantPortalLink(...args),
  };
});

const getApplicationReviewQueue = vi.fn();
const getApplication = vi.fn();
const decideApplication = vi.fn();

vi.mock("@/applications/applicationReviewModel", () => ({
  getApplicationReviewQueue: () => getApplicationReviewQueue(),
  getApplication: (...args: unknown[]) => getApplication(...args),
  decideApplication: (...args: unknown[]) => decideApplication(...args),
}));

vi.mock("@/session/sessionContext", () => ({
  sessionOrganizationSlug: { value: "northwood-collective" },
}));

const branding = {
  displayName: "Northwood",
  lettermark: "N",
  palette: null,
  fullLockupUrl: null,
  compactMarkUrl: null,
};

function organizationPayload(overrides: Record<string, unknown> = {}) {
  return {
    organizationSlug: "northwood-collective",
    organizationName: "Northwood",
    acceptsOrganizationApplications: false,
    branding,
    events: [
      {
        slug: "emberfall-2026",
        name: "Emberfall 2026",
        startsAt: "2026-09-12T00:00:00+00:00",
        endsAt: null,
        timezone: "UTC",
      },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  routeParams.organizationSlug = "northwood-collective";
  delete routeParams.eventSlug;
});

describe("ParticipationView", () => {
  it("invites a visitor to choose an open event", async () => {
    getOrganizationParticipation.mockResolvedValue(organizationPayload());

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.text()).toContain("Join Northwood");
    expect(wrapper.text()).toContain("Emberfall 2026");
    // APP-018: off by default, so no organization-level offer appears.
    expect(wrapper.text()).not.toContain("Apply to join Northwood");
  });

  it("offers to join the organization when it accepts organization applications", async () => {
    getOrganizationParticipation.mockResolvedValue(
      organizationPayload({ acceptsOrganizationApplications: true }),
    );

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.text()).toContain("Apply to join Northwood");
  });

  it("says so plainly when nothing is open", async () => {
    getOrganizationParticipation.mockResolvedValue(
      organizationPayload({
        events: [],
        acceptsOrganizationApplications: false,
      }),
    );

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.text()).toContain("not accepting applications right now");
  });

  it("submits an event application with its department interests", async () => {
    routeParams.eventSlug = "emberfall-2026";
    getEventParticipation.mockResolvedValue({
      organizationSlug: "northwood-collective",
      organizationName: "Northwood",
      branding,
      event: {
        slug: "emberfall-2026",
        name: "Emberfall 2026",
        startsAt: null,
        endsAt: null,
        timezone: null,
        acceptingApplications: true,
      },
      departmentInterests: [{ id: "dept-1", name: "Gate" }],
    });
    submitApplication.mockResolvedValue({
      applicationId: "app-1",
      scope: "event",
    });

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.text()).toContain("Apply to staff Emberfall 2026");
    // APP-011: the field is present, optional, and named as interest.
    expect(wrapper.text()).toContain("Department interest");
    expect(wrapper.text()).toContain("commits neither of you to anything");

    await wrapper.find('input[type="text"]').setValue("Robin Hale");
    await wrapper.find('input[type="email"]').setValue("robin@example.test");
    await wrapper.find('input[type="checkbox"]').setValue(true);
    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(submitApplication).toHaveBeenCalledWith("northwood-collective", {
      applicantLegalName: "Robin Hale",
      applicantEmail: "robin@example.test",
      eventSlug: "emberfall-2026",
      departmentInterestIds: ["dept-1"],
    });
    expect(wrapper.text()).toContain("Your application is in");
  });

  it("reports a closed event instead of offering a form", async () => {
    routeParams.eventSlug = "emberfall-2026";
    getEventParticipation.mockResolvedValue({
      organizationSlug: "northwood-collective",
      organizationName: "Northwood",
      branding,
      event: {
        slug: "emberfall-2026",
        name: "Emberfall 2026",
        startsAt: null,
        endsAt: null,
        timezone: null,
        acceptingApplications: false,
      },
      departmentInterests: [],
    });

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.text()).toContain("no longer accepting applications");
    // The application form, specifically: the portal request form below it is
    // offered in every state of the page (APP-012).
    expect(wrapper.find(".participate__form").exists()).toBe(false);
  });

  // M18.22: APP-012 puts the way back to an existing application on the public
  // application surface.
  it("asks for an applicant portal link and says the same thing either way", async () => {
    getOrganizationParticipation.mockResolvedValue(organizationPayload());
    requestApplicantPortalLink.mockResolvedValue(undefined);

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.text()).toContain("Applied already?");

    await wrapper
      .find(".participate__portal-form input[type='email']")
      .setValue("robin@example.test");
    await wrapper.find(".participate__portal-form").trigger("submit");
    await flushPromises();

    expect(requestApplicantPortalLink).toHaveBeenCalledWith(
      "robin@example.test",
    );
    // APP-014: the confirmation is conditional wording, not a report of what
    // the node found.
    expect(wrapper.text()).toContain(
      "If that address has any applications, a link to them is on its way",
    );
  });

  it("offers the portal on a closed event, which is where an old applicant lands", async () => {
    routeParams.eventSlug = "emberfall-2026";
    getEventParticipation.mockResolvedValue({
      organizationSlug: "northwood-collective",
      organizationName: "Northwood",
      branding,
      event: {
        slug: "emberfall-2026",
        name: "Emberfall 2026",
        startsAt: null,
        endsAt: null,
        timezone: null,
        acceptingApplications: false,
      },
      departmentInterests: [],
    });

    const wrapper = mount(ParticipationView);
    await flushPromises();

    expect(wrapper.find(".participate__portal-form").exists()).toBe(true);
  });

  it("reports a refused portal link request without claiming mail was sent", async () => {
    getOrganizationParticipation.mockResolvedValue(organizationPayload());
    requestApplicantPortalLink.mockRejectedValue(
      new MeridianApiError("Too many requests", 429, {
        message: "Too many link requests from here. Try again later.",
      }),
    );

    const wrapper = mount(ParticipationView);
    await flushPromises();

    await wrapper
      .find(".participate__portal-form input[type='email']")
      .setValue("robin@example.test");
    await wrapper.find(".participate__portal-form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Too many link requests");
    expect(wrapper.text()).not.toContain("on its way");
  });
});

describe("participationLink", () => {
  it("builds a readable address carrying no token (APP-017)", () => {
    expect(participationLink("northwood-collective")).toMatch(
      /\/apply\/northwood-collective$/,
    );
    expect(participationLink("northwood-collective", "emberfall-2026")).toMatch(
      /\/apply\/northwood-collective\/emberfall-2026$/,
    );
  });
});

describe("OrganizerApplicationsView", () => {
  const submitted = {
    id: "app-1",
    scope: "event" as const,
    organizationId: "org-1",
    organizationName: "Northwood",
    eventId: "event-1",
    eventName: "Emberfall 2026",
    applicantLegalName: "Robin Hale",
    applicantEmail: "robin@example.test",
    status: "submitted" as const,
    statusLabel: "Submitted",
    submittedAt: "2026-08-01T00:00:00+00:00",
    reviewedAt: null,
    reviewedBy: null,
    decisionReason: null,
    departmentInterests: [{ id: "dept-1", name: "Gate", archived: false }],
    canReview: true,
  };

  it("shows the shareable participation link (APP-017)", async () => {
    getApplicationReviewQueue.mockResolvedValue({
      canReview: true,
      hasDepartmentLeadVisibility: false,
      applications: [submitted],
    });

    const wrapper = mount(OrganizerApplicationsView);
    await flushPromises();

    const field = wrapper.find<HTMLInputElement>(".applications__share-input");

    expect(field.exists()).toBe(true);
    expect(field.element.value).toContain("/apply/northwood-collective");
  });

  it("requires a reason before rejection is available", async () => {
    getApplicationReviewQueue.mockResolvedValue({
      canReview: true,
      hasDepartmentLeadVisibility: false,
      applications: [submitted],
    });

    const wrapper = mount(OrganizerApplicationsView);
    await flushPromises();

    const buttons = wrapper.findAll("button");
    const reject = buttons.find((button) => button.text() === "Reject");

    expect(reject?.attributes("disabled")).toBeDefined();

    await wrapper
      .find(".applications__reason input")
      .setValue("Fully staffed.");
    await flushPromises();

    const enabled = wrapper
      .findAll("button")
      .find((button) => button.text() === "Reject");

    expect(enabled?.attributes("disabled")).toBeUndefined();
  });

  it("renders a department lead's row read-only (APP-011)", async () => {
    getApplicationReviewQueue.mockResolvedValue({
      canReview: false,
      hasDepartmentLeadVisibility: true,
      applications: [{ ...submitted, canReview: false }],
    });

    const wrapper = mount(OrganizerApplicationsView);
    await flushPromises();

    expect(wrapper.text()).toContain("named a department you lead");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Approve"),
    ).toBe(false);
  });

  it("names an organization-scoped application as having no event (APP-001)", async () => {
    getApplicationReviewQueue.mockResolvedValue({
      canReview: true,
      hasDepartmentLeadVisibility: false,
      applications: [
        {
          ...submitted,
          scope: "organization" as const,
          eventId: null,
          eventName: null,
        },
      ],
    });

    const wrapper = mount(OrganizerApplicationsView);
    await flushPromises();

    expect(wrapper.text()).toContain("Northwood — no event");
  });

  it("opens each row's detail surface by address (M18.29)", async () => {
    getApplicationReviewQueue.mockResolvedValue({
      canReview: true,
      hasDepartmentLeadVisibility: false,
      applications: [submitted],
    });

    const wrapper = mount(OrganizerApplicationsView);
    await flushPromises();

    expect(wrapper.get(".applications__applicant a").text()).toBe("Robin Hale");
  });
});

describe("OrganizerApplicationDetailView", () => {
  const submitted = {
    id: "app-1",
    scope: "event" as const,
    organizationId: "org-1",
    organizationName: "Northwood",
    eventId: "event-1",
    eventName: "Emberfall 2026",
    applicantLegalName: "Robin Hale",
    applicantEmail: "robin@example.test",
    status: "submitted" as const,
    statusLabel: "Submitted",
    submittedAt: "2026-08-01T00:00:00+00:00",
    reviewedAt: null,
    reviewedBy: null,
    decisionReason: null,
    departmentInterests: [{ id: "dept-1", name: "Gate", archived: false }],
    canReview: true,
  };

  beforeEach(() => {
    routeParams.applicationId = "app-1";
  });

  it("reads the application by id rather than reusing the queue", async () => {
    // The queue is filtered, so a link somebody followed often names an
    // application the last list read never contained.
    getApplication.mockResolvedValue(submitted);

    mount(OrganizerApplicationDetailView);
    await flushPromises();

    expect(getApplication).toHaveBeenCalledWith("app-1");
  });

  it("shows what the applicant submitted, including department interest", async () => {
    getApplication.mockResolvedValue(submitted);

    const wrapper = mount(OrganizerApplicationDetailView);
    await flushPromises();

    expect(wrapper.text()).toContain("Robin Hale");
    expect(wrapper.text()).toContain("robin@example.test");
    expect(wrapper.text()).toContain("Emberfall 2026");
    expect(wrapper.text()).toContain("Gate");
    // APP-011: interest, and never presented as an assignment, and never
    // editable during review in Alpha 1.
    expect(wrapper.text()).toContain("not an assignment");
    expect(wrapper.findAll("select")).toHaveLength(0);
  });

  it("says plainly when no department interest was recorded (APP-011)", async () => {
    getApplication.mockResolvedValue({
      ...submitted,
      departmentInterests: [],
    });

    const wrapper = mount(OrganizerApplicationDetailView);
    await flushPromises();

    expect(wrapper.text()).toContain("No department preference");
  });

  it("requires a reason before rejection is available", async () => {
    getApplication.mockResolvedValue(submitted);

    const wrapper = mount(OrganizerApplicationDetailView);
    await flushPromises();

    const reject = () =>
      wrapper.findAll("button").find((button) => button.text() === "Reject");

    expect(reject()?.attributes("disabled")).toBeDefined();

    await wrapper.find(".application__reason input").setValue("Fully staffed.");
    await flushPromises();

    expect(reject()?.attributes("disabled")).toBeUndefined();
  });

  it("offers no decision controls on a department lead's read-only view (APP-011)", async () => {
    getApplication.mockResolvedValue({ ...submitted, canReview: false });

    const wrapper = mount(OrganizerApplicationDetailView);
    await flushPromises();

    expect(wrapper.text()).toContain("named a department you lead");
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Approve"),
    ).toBe(false);
  });

  it("shows who decided an application and why (APP-003; requirements 2.4)", async () => {
    getApplication.mockResolvedValue({
      ...submitted,
      status: "rejected" as const,
      statusLabel: "Rejected",
      reviewedAt: "2026-08-02T00:00:00+00:00",
      reviewedBy: "Olive Organizer",
      decisionReason: "We are fully staffed for this event.",
    });

    const wrapper = mount(OrganizerApplicationDetailView);
    await flushPromises();

    expect(wrapper.text()).toContain("Rejected");
    expect(wrapper.text()).toContain("Olive Organizer");
    expect(wrapper.text()).toContain("We are fully staffed for this event.");
    // A decided application offers no second decision.
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Approve"),
    ).toBe(false);
  });

  it("states the refusal when the node will not answer for this application", async () => {
    // The node's own sentence, not a second copy of its rules written here.
    getApplication.mockRejectedValue(
      new MeridianApiError("Forbidden", 403, {
        message: "You may not read this application.",
      }),
    );

    const wrapper = mount(OrganizerApplicationDetailView);
    await flushPromises();

    expect(wrapper.get(".application__notice").text()).toContain(
      "You may not read this application.",
    );
  });
});
