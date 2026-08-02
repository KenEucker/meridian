// The document acknowledgment path's three surfaces against a stubbed node
// (M18.6; POL-023 through POL-027, POL-043 through POL-047; CLIENT-005,
// CLIENT-006, CLIENT-023, CLIENT-024).
//
// Three surfaces, one read, and the tests come in three groups for that reason
// rather than because they are three featuresets. What each group is really
// checking is a different half of the same claim:
//
//   signup       the document is on screen before anybody accepts it, and only
//                the outstanding signup-context items are in the way
//   staff ledger the version accepted is kept and stated, and a document that
//                moved afterwards does not become a task again (POL-043,
//                POL-045)
//   organizer    the create form offers only what the command accepts, and a
//                retired requirement keeps what was recorded against it
//
// And running through all three: an acknowledgment is not a gate. POL-026 and
// POL-027 keep it out of shift signup and credential eligibility, and a surface
// listing outstanding items has to say so, because that shape reads as a
// blocker to anybody who does not already know it is not one.
//
// No server runs for any of it (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { LOCAL_FIELD_DEPARTMENT_IDS } from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_ORGANIZATION_ID,
  localFieldSessionDocument,
} from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import OrganizerDocumentAcknowledgmentsView from "@/views/OrganizerDocumentAcknowledgmentsView.vue";
import SignupAcknowledgmentView from "@/views/SignupAcknowledgmentView.vue";
import StaffDocumentAcknowledgmentsView from "@/views/StaffDocumentAcknowledgmentsView.vue";

const MINE_PATH = "/api/document-acknowledgments/me";
const REVIEW_PATH = `/api/organizations/${LOCAL_FIELD_ORGANIZATION_ID}/document-acknowledgments`;
const ACKNOWLEDGE_PATH = "/api/commands/acknowledge-document";
const CREATE_REQUIREMENT_PATH =
  "/api/commands/create-document-acknowledgment-requirement";
const SET_ACTIVE_PATH =
  "/api/commands/set-document-acknowledgment-requirement-active";

const RADIO_REQUIREMENT_ID = "11111111-1111-4111-8111-111111111111";
const HEAT_REQUIREMENT_ID = "22222222-2222-4222-8222-222222222222";
const RADIO_DOCUMENT_ID = "33333333-3333-4333-8333-333333333333";

/** One requirement as the node reports it, defaulted so a test names its point. */
function requirementPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    requirement_id: RADIO_REQUIREMENT_ID,
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    scope_type: "organization",
    scope_label: "Organization: Northwood Collective",
    requirement_context: "signup",
    requirement_context_label: "Staff signup",
    document_type: "policy",
    document_id: RADIO_DOCUMENT_ID,
    document_title: "Radio safety",
    document_version: "3.02",
    rendered_html: "<p>Use the radio only when needed.</p>",
    acknowledged: false,
    acknowledged_at: null,
    acknowledged_version: null,
    document_changed_since: false,
    ...overrides,
  };
}

function reviewedRequirementPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: RADIO_REQUIREMENT_ID,
    scope_type: "organization",
    scope_id: LOCAL_FIELD_ORGANIZATION_ID,
    scope_label: "Organization: Northwood Collective",
    requirement_context: "signup",
    requirement_context_label: "Staff signup",
    document_type: "policy",
    document_id: RADIO_DOCUMENT_ID,
    document_title: "Radio safety",
    document_version: "3.02",
    document_published: true,
    active: true,
    subject_count: 2,
    acknowledged_count: 1,
    staff: [
      {
        staff_id: "s-1",
        display_name: "Wren Subject",
        handle: "wren",
        acknowledged: true,
        acknowledged_at: "2026-07-04T18:30:00+00:00",
        acknowledged_version: "3.01",
        document_changed_since: true,
      },
      {
        staff_id: "s-2",
        display_name: "River Subject",
        handle: null,
        acknowledged: false,
        acknowledged_at: null,
        acknowledged_version: null,
        document_changed_since: false,
      },
    ],
    ...overrides,
  };
}

function reviewPayload(
  requirements: readonly Record<string, unknown>[],
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    organization_name: "Northwood Collective",
    requirements,
    documents: [
      {
        document_type: "policy",
        document_id: RADIO_DOCUMENT_ID,
        title: "Radio safety",
        version: "3.02",
      },
    ],
    scopes: [
      {
        scope_type: "organization",
        scope_id: LOCAL_FIELD_ORGANIZATION_ID,
        label: "Organization: Northwood Collective",
      },
      {
        scope_type: "department",
        scope_id: LOCAL_FIELD_DEPARTMENT_IDS.gate,
        label: "Department: Gate",
      },
    ],
    contexts: [
      { value: "signup", label: "Staff signup" },
      { value: "training", label: "Training" },
    ],
    ...overrides,
  };
}

const GATING = {
  blocks_shift_signup: false,
  blocks_credential_eligibility: false,
  explanation:
    "Acknowledgments are recorded for the record. An outstanding one does not block shift signup or event credential eligibility.",
};

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

function stubNode(
  reply: (call: NodeCall) => { readonly status?: number; readonly body: unknown },
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

/** A node that answers `/me` with these requirements and nothing else. */
function stubNodeWithRequirements(
  requirements: readonly Record<string, unknown>[],
  reply: (
    call: NodeCall,
  ) => { readonly status?: number; readonly body: unknown } | null = () => null,
): readonly NodeCall[] {
  return stubNode((call) => {
    const answered = reply(call);

    if (answered !== null) {
      return answered;
    }

    if (call.url.endsWith(MINE_PATH)) {
      return {
        body: {
          requirements,
          outstanding_count: requirements.filter(
            (requirement) => requirement.acknowledged !== true,
          ).length,
          gating: GATING,
        },
      };
    }

    return { body: {} };
  });
}

function setNavigatorOnline(onLine: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value: onLine,
  });

  window.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

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
  setNavigatorOnline(true);
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  resetSelectedSessionDepartment();
  clearClientSession();
  setNavigatorOnline(true);
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

/** Work as the organizer, which holds the review capability. */
function actAsOrganizer(): void {
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
}

/** Work as a plain member, who holds nothing this catalog registers. */
function actAsMember(): void {
  installClientSession(
    localFieldSessionDocument({ roles: [], capabilities: [] }),
    "network",
  );
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);
}

async function mountView(
  component: unknown,
  routeName: string,
): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: routeName });
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function acceptButton(wrapper: VueWrapper) {
  return wrapper
    .findAll("button")
    .find((button) => button.text().startsWith("I have read this"));
}

describe("signup.policy-acknowledgment", () => {
  it("shows the document text before offering to accept it", async () => {
    stubNodeWithRequirements([requirementPayload()]);

    const wrapper = await mountView(
      SignupAcknowledgmentView,
      "signup.documents.acknowledge",
    );

    // POL-022: the node's render, fragments already resolved into document
    // text, and open rather than behind a disclosure control. Somebody cannot
    // acknowledge what they were never shown.
    const document = wrapper.get('[data-testid="acknowledgment-document"]');
    expect(document.html()).toContain("Use the radio only when needed.");
    expect(acceptButton(wrapper)).toBeTruthy();
    expect(wrapper.text()).toContain("3.02");
  });

  it("leaves training-context and already-answered requirements out of the way", async () => {
    stubNodeWithRequirements([
      requirementPayload({
        requirement_id: HEAT_REQUIREMENT_ID,
        document_title: "Heat safety",
        requirement_context: "training",
        requirement_context_label: "Training",
      }),
      requirementPayload({
        document_title: "Radio safety",
        acknowledged: true,
        acknowledged_version: "3.02",
        acknowledged_at: "2026-07-04T18:30:00+00:00",
      }),
    ]);

    const wrapper = await mountView(
      SignupAcknowledgmentView,
      "signup.documents.acknowledge",
    );

    // Signup is a step somebody is walking through. A step that also recites a
    // training requirement and what they already accepted makes the remaining
    // work harder to find.
    expect(wrapper.text()).not.toContain("Heat safety");
    expect(wrapper.text()).not.toContain("Radio safety");
    expect(wrapper.text()).toContain("Signup can continue");
  });

  it("sends the acknowledgment and reports the version the node recorded", async () => {
    const calls = stubNodeWithRequirements([requirementPayload()], (call) =>
      call.url.endsWith(ACKNOWLEDGE_PATH)
        ? {
            body: {
              requirement: requirementPayload({
                acknowledged: true,
                acknowledged_version: "3.02",
                acknowledged_at: "2026-08-01T17:00:00+00:00",
              }),
            },
          }
        : null,
    );

    const wrapper = await mountView(
      SignupAcknowledgmentView,
      "signup.documents.acknowledge",
    );

    await acceptButton(wrapper)!.trigger("click");
    await flushPromises();

    const commands = calls.filter((call) => call.url.endsWith(ACKNOWLEDGE_PATH));

    expect(commands).toHaveLength(1);
    expect(commands[0]?.method).toBe("POST");
    expect(commands[0]?.body).toEqual({
      requirement_id: RADIO_REQUIREMENT_ID,
    });

    // POL-043: the recorded version is what comes back and what is stated.
    expect(wrapper.text()).toContain("acknowledged at version 3.02");
  });

  it("refuses where it stands with no network rather than queueing", async () => {
    const calls = stubNodeWithRequirements([requirementPayload()]);

    const wrapper = await mountView(
      SignupAcknowledgmentView,
      "signup.documents.acknowledge",
    );

    setNavigatorOnline(false);
    await flushPromises();

    // The button is disabled and the reason is on screen, because an
    // acknowledgment records the version that was read — one held on a device
    // would name whichever version that device last cached.
    expect(acceptButton(wrapper)!.attributes("disabled")).toBeDefined();
    expect(wrapper.text()).toContain("not held on this device for later");
    expect(calls.filter((call) => call.method === "POST")).toHaveLength(0);
  });
});

describe("staff.document-acknowledgments", () => {
  it("keeps the accepted version beside the current one and does not re-require it", async () => {
    stubNodeWithRequirements([
      requirementPayload({
        acknowledged: true,
        acknowledged_version: "2.00",
        acknowledged_at: "2026-07-04T18:30:00+00:00",
        document_version: "3.00",
        document_changed_since: true,
      }),
    ]);

    const wrapper = await mountView(
      StaffDocumentAcknowledgmentsView,
      "staff.documents.acknowledgments",
    );

    // POL-043 on one side, POL-045 on the other: the version accepted is
    // recorded and stated, the document has moved, and the row stays answered.
    expect(wrapper.get('[data-testid="acknowledged-version"]').text()).toContain(
      "Version 2.00",
    );
    expect(wrapper.get('[data-testid="acknowledgment-changed"]').text()).toContain(
      "not being asked to acknowledge it again",
    );
    // Absent, not disabled (CLIENT-005): an answered row has nothing to offer,
    // and a greyed-out control standing where the action was reads as failure.
    expect(acceptButton(wrapper)).toBeUndefined();
    expect(wrapper.find('[data-testid="acknowledgment-outstanding"]').exists()).toBe(
      false,
    );
  });

  it("says an outstanding acknowledgment blocks neither shifts nor credentials", async () => {
    stubNodeWithRequirements([requirementPayload()]);

    const wrapper = await mountView(
      StaffDocumentAcknowledgmentsView,
      "staff.documents.acknowledgments",
    );

    // POL-026 and POL-027. A list of outstanding items reads like a list of
    // blockers unless it says otherwise, and somebody who believes an unread
    // policy is holding up their shift will not sign up for one.
    const gating = wrapper.get('[data-testid="acknowledgment-gating"]').text();
    expect(gating).toContain("does not block shift signup");
    expect(gating).toContain("credential eligibility");
    expect(wrapper.get('[data-testid="acknowledgment-outstanding"]').text()).toBe(
      "1 of 1 still to acknowledge.",
    );
  });

  it("opens to a member holding no capability at all", async () => {
    actAsMember();
    stubNodeWithRequirements([]);

    const wrapper = await mountView(
      StaffDocumentAcknowledgmentsView,
      "staff.documents.acknowledgments",
    );

    // Being asked to read something is a fact about a person rather than a
    // permission granted to them, so this page is gated by nothing and says so
    // plainly when the list is empty.
    expect(wrapper.text()).toContain("Nobody has asked you to acknowledge");
  });
});

describe("organizer.document-acknowledgments", () => {
  it("is absent for a client holding no review capability", async () => {
    actAsMember();
    const calls = stubNode(() => ({ body: {} }));

    const wrapper = await mountView(
      OrganizerDocumentAcknowledgmentsView,
      "organizer.document-acknowledgments.index",
    );

    // CLIENT-005: refused rather than shown an empty table, and nothing is
    // asked of the node on a page that would only be refused (CLIENT-006).
    expect(wrapper.text()).toContain("requires organizer authority");
    expect(calls).toHaveLength(0);
  });

  it("offers only the published documents and scopes the node named", async () => {
    actAsOrganizer();
    stubNode((call) =>
      call.url.endsWith(REVIEW_PATH)
        ? { body: reviewPayload([reviewedRequirementPayload()]) }
        : { body: {} },
    );

    const wrapper = await mountView(
      OrganizerDocumentAcknowledgmentsView,
      "organizer.document-acknowledgments.index",
    );

    // CLIENT-006: the choices are the node's answers, not a catalogue this
    // surface keeps. A draft would be refused by the command, so it is never
    // among them.
    const documents = wrapper
      .get('[data-testid="requirement-document"]')
      .findAll("option")
      .map((option) => option.text());
    expect(documents).toEqual([
      "Choose a published document",
      "Radio safety (version 3.02)",
    ]);

    const scopes = wrapper
      .get('[data-testid="requirement-scope"]')
      .findAll("option")
      .map((option) => option.text());
    // POL-047: organization and department, and no team scope.
    expect(scopes).toEqual([
      "Organization: Northwood Collective",
      "Department: Gate",
    ]);

    const contexts = wrapper
      .get('[data-testid="requirement-context"]')
      .findAll("option")
      .map((option) => option.text());
    // POL-046: signup and training, and nothing else.
    expect(contexts).toEqual(["Staff signup", "Training"]);
  });

  it("creates a requirement from the choices it was given", async () => {
    actAsOrganizer();
    const calls = stubNode((call) => {
      if (call.url.endsWith(CREATE_REQUIREMENT_PATH)) {
        return { body: { requirement: reviewedRequirementPayload() } };
      }

      return call.url.endsWith(REVIEW_PATH)
        ? { body: reviewPayload([]) }
        : { body: {} };
    });

    const wrapper = await mountView(
      OrganizerDocumentAcknowledgmentsView,
      "organizer.document-acknowledgments.index",
    );

    await wrapper
      .get('[data-testid="requirement-document"]')
      .setValue(`policy:${RADIO_DOCUMENT_ID}`);
    await wrapper
      .get('[data-testid="requirement-scope"]')
      .setValue(`department:${LOCAL_FIELD_DEPARTMENT_IDS.gate}`);
    await wrapper.get('[data-testid="requirement-context"]').setValue("training");

    await wrapper.get("form").trigger("submit");
    await flushPromises();

    const commands = calls.filter((call) =>
      call.url.endsWith(CREATE_REQUIREMENT_PATH),
    );

    expect(commands).toHaveLength(1);
    expect(commands[0]?.body).toEqual({
      organization_id: LOCAL_FIELD_ORGANIZATION_ID,
      document_type: "policy",
      document_id: RADIO_DOCUMENT_ID,
      scope_type: "department",
      scope_id: LOCAL_FIELD_DEPARTMENT_IDS.gate,
      requirement_context: "training",
    });
    expect(wrapper.text()).toContain("Radio safety is now required");
  });

  it("shows who answered and who has not, with names and no contact details", async () => {
    actAsOrganizer();
    stubNode((call) =>
      call.url.endsWith(REVIEW_PATH)
        ? { body: reviewPayload([reviewedRequirementPayload()]) }
        : { body: {} },
    );

    const wrapper = await mountView(
      OrganizerDocumentAcknowledgmentsView,
      "organizer.document-acknowledgments.index",
    );

    expect(wrapper.get('[data-testid="requirement-answered"]').text()).toBe(
      "1 of 2",
    );

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Who was asked")!
      .trigger("click");

    const subjects = wrapper.get('[data-testid="requirement-subjects"]').text();

    expect(subjects).toContain("Wren Subject");
    expect(subjects).toContain("Version 3.01");
    // POL-045 again, from the other side: the document moved and this person is
    // not being asked again.
    expect(subjects).toContain("is not re-required");
    expect(subjects).toContain("River Subject");
    expect(subjects).toContain("Not yet acknowledged");
  });

  it("retires a requirement and keeps what was recorded against it", async () => {
    actAsOrganizer();
    const calls = stubNode((call) => {
      if (call.url.endsWith(SET_ACTIVE_PATH)) {
        return {
          body: {
            requirement: reviewedRequirementPayload({ active: false }),
          },
        };
      }

      return call.url.endsWith(REVIEW_PATH)
        ? { body: reviewPayload([reviewedRequirementPayload()]) }
        : { body: {} };
    });

    const wrapper = await mountView(
      OrganizerDocumentAcknowledgmentsView,
      "organizer.document-acknowledgments.index",
    );

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Stop requiring")!
      .trigger("click");
    await flushPromises();

    expect(
      calls.filter((call) => call.url.endsWith(SET_ACTIVE_PATH))[0]?.body,
    ).toEqual({ requirement_id: RADIO_REQUIREMENT_ID, active: false });

    // Retired and still on screen, with its count intact. An organizer checking
    // whether something was ever asked is looking for exactly the row a tidier
    // list would have dropped, and the acknowledgments recorded against it are
    // still true.
    expect(wrapper.text()).toContain("Retired");
    expect(wrapper.get('[data-testid="requirement-answered"]').text()).toBe(
      "1 of 2",
    );
    expect(
      wrapper.findAll("button").some((button) => button.text() === "Require again"),
    ).toBe(true);
  });
});
