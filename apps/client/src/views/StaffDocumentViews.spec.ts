// The staff document library and one document in it, against a stubbed node
// (M18.7; POL-006, POL-008 through POL-013, POL-055; CLIENT-005, CLIENT-006,
// CLIENT-023, CLIENT-024).
//
// What is under test is the seam. Which documents reach a reader is the node's
// decision and was already enforced; these surfaces have to ask the right
// question, render the answer without adding to it, and say plainly when the
// answer is a refusal.
//
// The stub honors `state` and `q` the way `DocumentReadController` does, so a
// test that expects a draft to be absent proves the client asked for published
// documents rather than proving the fixture omitted one.
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { clearReadCache } from "@/offline/readCache";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory, type Router } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  LOCAL_FIELD_ORGANIZATION_ID,
  installLocalFieldSession,
  localFieldSessionDocument,
} from "@/session/localFieldSession";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import StaffDocumentDetailView from "@/views/StaffDocumentDetailView.vue";
import StaffDocumentLibraryView from "@/views/StaffDocumentLibraryView.vue";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;
const CONDUCT_POLICY_ID = "44444444-4444-4444-8444-444444444401";
const RADIO_PROCEDURE_ID = "44444444-4444-4444-8444-444444444402";
const DRAFT_POLICY_ID = "44444444-4444-4444-8444-444444444403";

/** One request this client made, as the assertions read it. */
interface NodeCall {
  readonly url: string;
  readonly method: string;
}

/** One document, as `SerializesProductDocuments::documentPayload` publishes it. */
function documentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: CONDUCT_POLICY_ID,
    document_type: "policy",
    organization_id: ORGANIZATION_ID,
    scope_type: "organization",
    scope_id: ORGANIZATION_ID,
    scope_label: "Organization: Northwood Collective",
    title: "Volunteer Conduct",
    slug: "volunteer-conduct",
    event_info_section: null,
    event_info_section_label: null,
    markdown_source: "# Volunteer Conduct\n\nBe excellent to each other.",
    rendered_html:
      "<h1>Volunteer Conduct</h1><p>Be excellent to each other.</p>",
    state: "published",
    state_label: "Published",
    version: "1.00",
    published_at: "2027-06-01T17:00:00+00:00",
    archived_at: null,
    updated_at: "2027-06-01T17:00:00+00:00",
    visibility_summary: "Published to staff in this organization.",
    export_formats: ["markdown", "pdf"],
    can_maintain: false,
    fragment_references: [],
    ...overrides,
  };
}

const SEEDED_DOCUMENTS = [
  documentPayload(),
  documentPayload({
    id: RADIO_PROCEDURE_ID,
    document_type: "procedure",
    scope_type: "department",
    scope_label: "Department: Rangers",
    title: "Radio Procedure",
    slug: "radio-procedure",
    rendered_html: "<p>Use plain language.</p>",
    visibility_summary: "Published to members of this department.",
  }),
  // A draft the caller maintains. The node returns it only when the surface
  // asks for one, which the reading library never does.
  documentPayload({
    id: DRAFT_POLICY_ID,
    title: "Unpublished Draft Policy",
    slug: "unpublished-draft-policy",
    state: "draft",
    state_label: "Draft",
    published_at: null,
    can_maintain: true,
    visibility_summary:
      "Draft and archived documents are visible only to permitted maintainers.",
  }),
];

/**
 * Answer as `DocumentReadController` would, and record what was asked.
 *
 * The state and search filters are applied here rather than assumed away: the
 * point of several of these tests is that the client narrows by asking, so the
 * stub has to be capable of answering the wider question it never asks.
 */
function stubNode(documents = SEEDED_DOCUMENTS): readonly NodeCall[] {
  const calls: NodeCall[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input));

      calls.push({ url: String(input), method: init?.method ?? "GET" });

      const single = documents.find((document) =>
        url.pathname.endsWith(`/${String(document.id)}`),
      );

      if (single !== undefined) {
        return jsonResponse(single);
      }

      const state = url.searchParams.get("state") ?? "all";
      const search = url.searchParams.get("q") ?? "";

      return jsonResponse({
        organization_id: ORGANIZATION_ID,
        access: { can_maintain: false, scopes: [] },
        event_info_sections: [],
        documents: documents
          .filter(
            (document) => state === "all" || document.state === state,
          )
          .filter(
            (document) =>
              search === "" ||
              String(document.title)
                .toLowerCase()
                .includes(search.toLowerCase()),
          ),
        fragments: [],
      });
    }),
  );

  return calls;
}

/** A node that answers one request with a refusal of its own wording. */
function stubRefusingNode(status: number, message: string): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => jsonResponse({ message }, status)),
  );
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
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
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  resetSelectedSessionDepartment();
  clearClientSession();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountLibrary(
  query: Record<string, string> = {},
): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "staff.documents.index", query });
  await router.isReady();

  const wrapper = mount(StaffDocumentLibraryView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return { wrapper, router };
}

async function mountDetail(
  documentType: string,
  documentId: string,
): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({
    name: "staff.documents.show",
    params: { documentType, documentId },
  });
  await router.isReady();

  const wrapper = mount(StaffDocumentDetailView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

describe("the staff document library", () => {
  it("reads the organization's published documents and lists what came back", async () => {
    const calls = stubNode();
    const { wrapper } = await mountLibrary();

    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/documents?state=published`,
    );
    expect(calls[0]?.method).toBe("GET");

    const text = wrapper.text();
    expect(text).toContain("Volunteer Conduct");
    expect(text).toContain("Radio Procedure");
    // Scope and version are the node's words for the row, not labels this page
    // keeps (CLIENT-006).
    expect(text).toContain("Organization: Northwood Collective");
    expect(text).toContain("Department: Rangers");
    expect(text).toContain("1.00");
  });

  it("leaves an unpublished document off the reading surface", async () => {
    // The required assertion, from both directions. For a non-maintainer the
    // node never returns the draft at all; for the maintainer who seeded it,
    // this surface still does not show it, because it asks for published
    // documents. A draft is not policy yet, and the authoring surface is where
    // it belongs (POL-004, POL-006).
    const calls = stubNode();
    const { wrapper } = await mountLibrary();

    expect(wrapper.text()).not.toContain("Unpublished Draft Policy");
    expect(
      calls.every((call) => call.url.includes("state=published")),
    ).toBe(true);
  });

  it("links each document to its own page rather than expanding it in the list", async () => {
    stubNode();
    const { wrapper } = await mountLibrary();

    const links = wrapper
      .findAll("a")
      .map((link) => link.attributes("href"))
      .filter((href): href is string => href !== undefined);

    expect(links).toContain(`/staff/documents/policy/${CONDUCT_POLICY_ID}`);
    expect(links).toContain(
      `/staff/documents/procedure/${RADIO_PROCEDURE_ID}`,
    );
  });

  it("sends a search to the node instead of narrowing the list it holds", async () => {
    // POL-055 through the seam: the node applies the search after deciding
    // visibility, so searching here would only search whichever documents
    // happened to arrive.
    const calls = stubNode();
    const { wrapper, router } = await mountLibrary();

    await wrapper.get("input[type=search]").setValue("Radio");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.query.q).toBe("Radio");
    expect(calls).toHaveLength(2);
    expect(calls[1]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/documents?state=published&q=Radio`,
    );

    expect(wrapper.text()).toContain("Radio Procedure");
    expect(wrapper.text()).not.toContain("Volunteer Conduct");
  });

  it("reads the search term out of the URL it was opened at", async () => {
    const calls = stubNode();
    const { wrapper } = await mountLibrary({ q: "Conduct" });

    expect(calls[0]?.url).toContain("q=Conduct");
    expect(wrapper.text()).toContain("Volunteer Conduct");
    expect(wrapper.text()).not.toContain("Radio Procedure");
  });

  it("says a search matched nothing rather than that nothing is published", async () => {
    stubNode();
    const { wrapper } = await mountLibrary({ q: "Parking" });

    expect(wrapper.text()).toContain(
      "No published document you can see has Parking in its title.",
    );
    expect(wrapper.text()).not.toContain(
      "No policies or procedures are published to you yet",
    );
  });

  it("says the read failed rather than showing an organization with nothing published", async () => {
    stubRefusingNode(500, "Something went wrong.");

    const { wrapper } = await mountLibrary();

    expect(wrapper.find('[role="alert"]').text()).toContain(
      "Something went wrong.",
    );
    expect(wrapper.text()).not.toContain(
      "No policies or procedures are published to you yet",
    );
  });

  it("states that the library needs an organization rather than reading without one", async () => {
    installClientSession(
      localFieldSessionDocument({
        context: {
          organization_id: null,
          event_id: null,
          department_id: null,
          node_locked: false,
          node_locked_event_id: null,
          switching_available: true,
        },
      }),
      "network",
    );

    const calls = stubNode();
    const { wrapper } = await mountLibrary();

    expect(calls).toHaveLength(0);
    expect(wrapper.text()).toContain(
      "Your document library opens once this device is working in an organization",
    );
  });
});

/*
 * Search when the node cannot be reached (M18.9).
 *
 * The library is one of the pages technical spec 9.3 asks a device to hold, and
 * a held library that answers a typed word with "unable to load" is the failure
 * this whole change is about. The narrowed request is its own cache key, so the
 * first search typed offline is always a miss on its own key and falls back to
 * the broad copy, which the browser then filters.
 */
describe("searching the staff document library offline", () => {
  it("matches against the copy this device holds instead of failing", async () => {
    stubNode();
    await mountLibrary();

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountLibrary({ q: "radio" });

    expect(wrapper.find(".staff-documents__error").exists()).toBe(false);
    expect(wrapper.text()).toContain("Radio Procedure");
    expect(wrapper.text()).not.toContain("Getting To Signal Camp");
    expect(wrapper.get(".staff-documents__narrowed").text()).toContain(
      "matched against the documents this device had already read",
    );
  });

  it("says a stored search found nothing rather than that nothing matches", async () => {
    stubNode();
    await mountLibrary();

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountLibrary({ q: "nothing-like-this" });

    expect(wrapper.find(".staff-documents__error").exists()).toBe(false);
    expect(wrapper.find(".staff-documents__narrowed").exists()).toBe(true);
  });

  it("still states an unreachable node when the device holds no library", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { wrapper } = await mountLibrary({ q: "radio" });

    expect(wrapper.get(".staff-documents__error").text()).toContain(
      "Unable to load documents",
    );
  });
});

describe("one staff document", () => {
  it("reads the document by its own type and renders the node's HTML", async () => {
    const calls = stubNode();
    const wrapper = await mountDetail("policy", CONDUCT_POLICY_ID);

    expect(calls[0]?.url).toBe(
      `http://node.test/api/policy-documents/${CONDUCT_POLICY_ID}`,
    );

    // The node resolved the fragments, stripped raw HTML, and refused unsafe
    // links (POL-022, POL-034, POL-035); the browser renders nothing of its own.
    expect(wrapper.html()).toContain(
      "<p>Be excellent to each other.</p>",
    );
    expect(wrapper.text()).toContain("Organization: Northwood Collective");
    expect(wrapper.text()).toContain("1.00");
    expect(wrapper.text()).toContain("Published to staff in this organization.");
  });

  it("asks the procedure endpoint for a procedure", async () => {
    // Policy and procedure are separate resources on the node rather than one
    // resource with a flag, which is why the type is in the address.
    const calls = stubNode();
    await mountDetail("procedure", RADIO_PROCEDURE_ID);

    expect(calls[0]?.url).toBe(
      `http://node.test/api/procedure-documents/${RADIO_PROCEDURE_ID}`,
    );
  });

  it("quotes the node's refusal for a document this reader may not see", async () => {
    // POL-013 at its edge: the library never offers this link, and a URL that
    // was typed or forwarded gets the refusal rather than an empty page.
    stubRefusingNode(403, "You do not have permission to view this document.");

    const wrapper = await mountDetail("policy", CONDUCT_POLICY_ID);

    expect(wrapper.find('[role="alert"]').text()).toContain(
      "You do not have permission to view this document.",
    );
  });

  it("says a draft is not published rather than presenting it as policy", async () => {
    stubNode();

    const wrapper = await mountDetail("policy", DRAFT_POLICY_ID);

    expect(wrapper.text()).toContain("This document is still a draft.");
    expect(wrapper.text()).toContain("Not published");
  });

  it("refuses an address that names neither a policy nor a procedure", async () => {
    const calls = stubNode();
    const wrapper = await mountDetail("fragment", CONDUCT_POLICY_ID);

    expect(calls).toHaveLength(0);
    expect(wrapper.find('[role="alert"]').text()).toContain(
      "That is not a policy or procedure address.",
    );
  });
});
