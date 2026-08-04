// The waiver administration surface against a stubbed node (M18.18;
// WAIVER-001 through WAIVER-006, WAIVER-010; CLIENT-005, CLIENT-006,
// CLIENT-024).
//
// The claims under test are the surface's half of WAIVER-010:
//
//   authority   the page renders what the node answered — the caller's own
//               scopes and waivers — and renders the node's refusal for a
//               caller who maintains nothing, rather than predicting either
//   creation    the create form offers only the node's scope and document
//               options, and the command carries exactly what was chosen
//   completion  recording goes through the command with the chosen staff
//               member, and the roster names lapsed as its own state, because
//               lapsed is what WAIVER-006 turns into a credential block
//   document    a document-backed waiver's text is on screen, fragments
//               already inline (WAIVER-007; POL-022), where completion is
//               recorded
//
// No server runs for any of it (CLIENT-024).

import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  installLocalFieldSession,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSessionFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import WaiverAdministrationView from "@/views/WaiverAdministrationView.vue";

const INDEX_PATH = `/api/organizations/${LOCAL_FIELD_ORGANIZATION_ID}/waivers`;
const CREATE_PATH = "/api/commands/create-waiver";
const COMPLETION_PATH = "/api/commands/record-waiver-completion";

const WAIVER_ID = "11111111-1111-4111-8111-111111111111";
const DOCUMENT_ID = "22222222-2222-4222-8222-222222222222";

function waiverPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: WAIVER_ID,
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    scope_type: "organization",
    scope_id: LOCAL_FIELD_ORGANIZATION_ID,
    scope_label: "Organization: Northwood Collective",
    name: "General Liability Waiver",
    description: null,
    expires_after_days: 365,
    document: {
      document_type: "policy",
      document_id: DOCUMENT_ID,
      title: "Event Liability Policy",
      version: "2.01",
      published: true,
    },
    archived: false,
    archived_at: null,
    current_completion_count: 1,
    total_completion_count: 2,
    ...overrides,
  };
}

function indexPayload(
  waivers: readonly Record<string, unknown>[],
): Record<string, unknown> {
  return {
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    organization_name: "Northwood Collective",
    scopes: [
      {
        scope_type: "organization",
        scope_id: LOCAL_FIELD_ORGANIZATION_ID,
        label: "Organization: Northwood Collective",
      },
    ],
    documents: [
      {
        document_type: "policy",
        document_id: DOCUMENT_ID,
        title: "Event Liability Policy",
        version: "2.01",
      },
    ],
    waivers,
  };
}

function detailPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    waiver: waiverPayload(),
    rendered_document: {
      title: "Event Liability Policy",
      version: "2.01",
      rendered_html:
        "<p>You acknowledge the <strong>inherent risks</strong> of event work.</p>",
    },
    document_unavailable_reason: null,
    roster: [
      {
        staff_id: "s-1",
        display_name: "Wren Signer",
        handle: "wren",
        complete: true,
        lapsed: false,
        completed_at: "2026-07-01T18:30:00+00:00",
        expires_at: "2027-07-01T18:30:00+00:00",
        acknowledged_version: "2.01",
      },
      {
        staff_id: "s-2",
        display_name: "River Lapsed",
        handle: null,
        complete: false,
        lapsed: true,
        completed_at: "2025-06-01T18:30:00+00:00",
        expires_at: "2026-06-01T18:30:00+00:00",
        acknowledged_version: "1.00",
      },
      {
        staff_id: "s-3",
        display_name: "Ash Unsigned",
        handle: null,
        complete: false,
        lapsed: false,
        completed_at: null,
        expires_at: null,
        acknowledged_version: null,
      },
    ],
    ...overrides,
  };
}

interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

function stubNode(
  reply: (
    call: NodeCall,
  ) => { readonly status?: number; readonly body: unknown } | null,
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

      const answer = reply(call) ?? { body: {} };

      return new Response(JSON.stringify(answer.body), {
        status: answer.status ?? 200,
        headers: { "content-type": "application/json" },
      });
    }),
  );

  return calls;
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

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "organizer.waivers.index" });
  await router.isReady();

  const wrapper = mount(WaiverAdministrationView as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

it("renders the caller's waivers with scope, document, and expiry", async () => {
  stubNode((call) =>
    call.url.endsWith(INDEX_PATH)
      ? { body: indexPayload([waiverPayload()]) }
      : null,
  );

  const wrapper = await mountView();

  expect(wrapper.text()).toContain("General Liability Waiver");
  expect(wrapper.text()).toContain("Organization: Northwood Collective");
  expect(wrapper.text()).toContain("365 days after completion");
  expect(wrapper.find('[data-testid="waiver-document-title"]').text()).toContain(
    "Event Liability Policy",
  );
});

it("renders the node's refusal for a caller who maintains no scope", async () => {
  // WAIVER-010 / CLIENT-006: authority is the node's answer, and the refusal
  // is a state of the page, not an error.
  stubNode((call) =>
    call.url.endsWith(INDEX_PATH)
      ? {
          status: 403,
          body: {
            message: "You do not have permission to administer these waivers.",
          },
        }
      : null,
  );

  const wrapper = await mountView();

  expect(wrapper.find('[data-testid="waivers-refused"]').exists()).toBe(true);
  expect(wrapper.find('[data-testid="waiver-submit"]').exists()).toBe(false);
});

it("creates a waiver carrying exactly what was chosen", async () => {
  const calls = stubNode((call) => {
    if (call.url.endsWith(INDEX_PATH)) {
      return { body: indexPayload([]) };
    }

    if (call.url.endsWith(CREATE_PATH)) {
      return { status: 201, body: { waiver: waiverPayload() } };
    }

    return null;
  });

  const wrapper = await mountView();

  await wrapper.find('[data-testid="waiver-name"]').setValue("Night Gate Waiver");
  await wrapper.find('[data-testid="waiver-expiry"]').setValue("30");
  await wrapper
    .find('[data-testid="waiver-document"]')
    .setValue(`policy:${DOCUMENT_ID}`);
  await wrapper.find("form.waivers__form").trigger("submit");
  await flushPromises();

  const create = calls.find((call) => call.url.endsWith(CREATE_PATH));

  expect(create).toBeDefined();
  expect(create?.body).toMatchObject({
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    scope_type: "organization",
    scope_id: LOCAL_FIELD_ORGANIZATION_ID,
    name: "Night Gate Waiver",
    expires_after_days: 30,
    document_type: "policy",
    document_id: DOCUMENT_ID,
  });
});

it("shows the referenced text inline and names lapsed as its own state", async () => {
  stubNode((call) => {
    if (call.url.endsWith(`${INDEX_PATH}/${WAIVER_ID}`)) {
      return { body: detailPayload() };
    }

    if (call.url.endsWith(INDEX_PATH)) {
      return { body: indexPayload([waiverPayload()]) };
    }

    return null;
  });

  const wrapper = await mountView();

  await wrapper.find(".waivers__toggle").trigger("click");
  await flushPromises();

  // WAIVER-007 / POL-022: the fragment text arrives as document text.
  const rendered = wrapper.find('[data-testid="waiver-rendered-document"]');

  expect(rendered.exists()).toBe(true);
  expect(rendered.html()).toContain("<strong>inherent risks</strong>");

  // WAIVER-002 / WAIVER-006: a lapsed completion is not merely incomplete.
  const roster = wrapper.find('[data-testid="waiver-roster"]');

  expect(roster.text()).toContain("River Lapsed");
  expect(roster.text()).toContain("Lapsed");
  expect(roster.text()).toContain("Ash Unsigned");
  expect(roster.text()).toContain("Incomplete");
});

it("records a completion for the chosen staff member", async () => {
  const calls = stubNode((call) => {
    if (call.url.endsWith(`${INDEX_PATH}/${WAIVER_ID}`)) {
      return { body: detailPayload() };
    }

    if (call.url.endsWith(INDEX_PATH)) {
      return { body: indexPayload([waiverPayload()]) };
    }

    if (call.url.endsWith(COMPLETION_PATH)) {
      return { status: 201, body: {} };
    }

    return null;
  });

  const wrapper = await mountView();

  await wrapper.find(".waivers__toggle").trigger("click");
  await flushPromises();

  await wrapper.find('[data-testid="completion-staff"]').setValue("s-3");
  await wrapper.find("form.waivers__completion").trigger("submit");
  await flushPromises();

  const completion = calls.find((call) => call.url.endsWith(COMPLETION_PATH));

  expect(completion).toBeDefined();
  expect(completion?.body).toMatchObject({
    waiver_id: WAIVER_ID,
    staff_id: "s-3",
  });
});
