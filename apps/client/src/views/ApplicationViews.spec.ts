// The participation surface and the reviewer's queue (M18.21A; APP-001,
// APP-011, APP-016, APP-017, APP-018, APP-019).

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { participationLink } from "@/applications/participationModel";
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
  };
});

const getApplicationReviewQueue = vi.fn();
const decideApplication = vi.fn();

vi.mock("@/applications/applicationReviewModel", () => ({
  getApplicationReviewQueue: () => getApplicationReviewQueue(),
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
    expect(wrapper.find("form").exists()).toBe(false);
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
});
