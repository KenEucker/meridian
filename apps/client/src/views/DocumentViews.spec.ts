// The policy, procedure, and fragment surfaces against a stubbed node (M16.19;
// CLIENT-023, CLIENT-024, CLIENT-019, CLIENT-020; data/API 11.1 through 11.7,
// 11.4A, 5.7).
//
// These were fixture tests. They installed a role, mounted the library over
// eight compiled-in documents, saved a policy, and asserted that the browser's
// own copy of `DocumentAdminService` had stored it. Nothing in them reached an
// endpoint, so nothing in them said whether the screen and the server agreed on
// a URL, a request body, or a response shape — and the rules they proved were
// the client's, not the node's.
//
// They now stub `fetch` and answer with the payloads `DocumentReadController`,
// the document commands, and the short-lived download URL endpoints publish.
// Each test therefore asserts two things: what the screen asked the node, and
// what it did with the answer. The refusals are the node's sentences, quoted
// back.
//
// No server runs for any of this, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_TEAM_IDS,
} from "@/field-reports/localFieldFixture";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  LOCAL_FIELD_ORGANIZATION_ID,
  installLocalFieldSession,
} from "@/session/localFieldSession";
import { selectSessionDepartment } from "@/session/sessionAccess";
import DocumentEditView from "@/views/DocumentEditView.vue";
import DocumentLibraryView from "@/views/DocumentLibraryView.vue";

const ORGANIZATION_ID = LOCAL_FIELD_ORGANIZATION_ID;
const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const TEAM_ID = LOCAL_FIELD_TEAM_IDS.rangersDirt;
const CONDUCT_POLICY_ID = "44444444-4444-4444-8444-444444444401";
const RADIO_PROCEDURE_ID = "44444444-4444-4444-8444-444444444402";
const FRAGMENT_ID = "55555555-5555-4555-8555-555555555501";

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
 * `reply` sees the URL and the parsed body so a test can vary its answer over
 * the run. Several of these need the read after a write to differ from the read
 * before it, which is exactly the behavior they are there to prove.
 */
function stubNode(reply: (call: NodeCall) => NodeReply): readonly NodeCall[] {
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
    scope_label: "Organization: Idaho Burners",
    title: "Volunteer Conduct",
    slug: "volunteer-conduct",
    event_info_section: "requirements",
    event_info_section_label: "Event requirements",
    markdown_source: "# Volunteer Conduct\n\n{{fragment:shared-conduct}}",
    rendered_html:
      "<h1>Volunteer Conduct</h1>\n<p>Treat people, radios, and camp spaces with care.</p>",
    state: "published",
    state_label: "Published",
    version: "1.00",
    published_at: "2026-07-01T16:00:00+00:00",
    archived_at: null,
    updated_at: "2026-07-01T16:00:00+00:00",
    fragment_references: [
      {
        token: "{{fragment:shared-conduct}}",
        fragment_id: FRAGMENT_ID,
        fragment_name: "Shared Conduct",
        fragment_slug: "shared-conduct",
        fragment_version: 1,
        fragment_version_at_last_edit: 1,
      },
    ],
    visibility_summary: "Published to staff in this organization.",
    export_formats: ["markdown", "pdf"],
    can_maintain: true,
    ...overrides,
  };
}

function procedurePayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return documentPayload({
    id: RADIO_PROCEDURE_ID,
    document_type: "procedure",
    scope_type: "department",
    scope_id: DEPARTMENT_ID,
    scope_label: "Department: Rangers",
    title: "Radio Checkout",
    slug: "radio-checkout",
    event_info_section: null,
    event_info_section_label: null,
    markdown_source: "Issue radios from Logistics.",
    rendered_html: "<p>Issue radios from Logistics.</p>",
    state: "draft",
    state_label: "Draft",
    published_at: null,
    fragment_references: [],
    visibility_summary:
      "Draft and archived documents are visible only to permitted maintainers.",
    ...overrides,
  });
}

/** One fragment, as `SerializesProductDocuments::fragmentPayload` publishes it. */
function fragmentPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    id: FRAGMENT_ID,
    organization_id: ORGANIZATION_ID,
    scope_type: "team",
    scope_id: TEAM_ID,
    scope_label: "Team: Dirt",
    name: "Shared Conduct",
    slug: "shared-conduct",
    markdown_source: "Treat people, radios, and camp spaces with care.",
    version: 1,
    updated_at: "2026-07-01T15:30:00+00:00",
    referencing_documents: [
      {
        id: CONDUCT_POLICY_ID,
        document_type: "policy",
        title: "Volunteer Conduct",
        state: "published",
        version: "1.00",
        published: true,
      },
      {
        id: RADIO_PROCEDURE_ID,
        document_type: "procedure",
        title: "Radio Checkout",
        state: "draft",
        version: "1.00",
        published: false,
      },
    ],
    ...overrides,
  };
}

/** The Event Info placements the index offers, as `EventInfoSection` names them. */
const EVENT_INFO_SECTIONS = [
  { value: "directions", label: "How to get to the event" },
  { value: "arrival", label: "Arrival requirements" },
  { value: "packing", label: "What to bring" },
  { value: "food", label: "Food" },
  { value: "housing", label: "Housing" },
  { value: "requirements", label: "Event requirements" },
];

/** The `GET /api/organizations/{id}/documents` envelope. */
function libraryPayload(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    organization_id: ORGANIZATION_ID,
    access: {
      can_maintain: true,
      scopes: [
        {
          scope_type: "organization",
          scope_id: ORGANIZATION_ID,
          label: "Organization: Idaho Burners",
        },
        {
          scope_type: "department",
          scope_id: DEPARTMENT_ID,
          label: "Department: Rangers",
        },
      ],
    },
    event_info_sections: EVENT_INFO_SECTIONS,
    documents: [documentPayload(), procedurePayload()],
    fragments: [fragmentPayload()],
    ...overrides,
  };
}

/** What a reader is answered with: published documents, no scopes, no fragments. */
function readerLibraryPayload(): Record<string, unknown> {
  return libraryPayload({
    access: { can_maintain: false, scopes: [] },
    documents: [documentPayload({ can_maintain: false })],
    fragments: [],
  });
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

/*
 * Every wrapper this file mounts, so `afterEach` can take them down. The pages
 * watch the route and re-read when it changes; one left mounted keeps reacting
 * into the next test's stub and the next test's recorded calls.
 */
const mounted: VueWrapper[] = [];

beforeEach(() => {
  installLocalFieldSession();
  selectSessionDepartment(DEPARTMENT_ID);
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  configureMeridianApi(null);
  clearClientSession();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountAt(
  component: unknown,
  location: Record<string, unknown>,
): Promise<VueWrapper> {
  const router = buildRouter();

  await router.push(location as never);
  await router.isReady();

  const wrapper = mount(component as never, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

function departmentLibraryLocation(): Record<string, unknown> {
  return {
    name: "events.departments.documents.index",
    params: { eventId: EVENT_ID, departmentId: DEPARTMENT_ID },
  };
}

function libraryReads(calls: readonly NodeCall[]): readonly NodeCall[] {
  return calls.filter(
    (call) => call.method === "GET" && call.url.includes("/documents"),
  );
}

function commandCalls(
  calls: readonly NodeCall[],
  command: string,
): readonly NodeCall[] {
  return calls.filter((call) => call.url.endsWith(`/commands/${command}`));
}

function buttonWithText(wrapper: VueWrapper, text: string) {
  const found = wrapper
    .findAll("button")
    .find((candidate) => candidate.text() === text);

  if (!found) {
    throw new Error(`No button labelled "${text}".`);
  }

  return found;
}

describe("the document library surface", () => {
  it("registers organizer and department document routes", () => {
    const names = routes.map((route) => route.name);

    expect(names).toContain("organizer.documents.index");
    expect(names).toContain("organizer.documents.create");
    expect(names).toContain("organizer.documents.edit");
    expect(names).toContain("events.departments.documents.index");
    expect(names).toContain("events.departments.documents.create");
    expect(names).toContain("events.departments.documents.edit");
  });

  it("reads the organization's documents and renders the node's answer", async () => {
    const calls = stubNode(() => ({ body: libraryPayload() }));

    const wrapper = await mountAt(
      DocumentLibraryView,
      departmentLibraryLocation(),
    );

    expect(libraryReads(calls)[0]?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/documents`,
    );

    // Every column is the node's own words: its state label, its scope label,
    // its version, its Event Info placement, and its visibility summary.
    expect(wrapper.text()).toContain("Volunteer Conduct");
    expect(wrapper.text()).toContain("Radio Checkout");
    expect(wrapper.text()).toContain("Organization: Idaho Burners");
    expect(wrapper.text()).toContain("Event requirements");
    expect(wrapper.text()).toContain("Published to staff in this organization.");
    expect(wrapper.text()).toContain("1.00");
    // A document with no placement says so rather than showing an empty cell.
    expect(wrapper.text()).toContain("Not shown");
    // Fragment impact is counted over what the fragment's read reported.
    expect(wrapper.text()).toContain("Published reference impact: 1");
  });

  it("sends the state filter to the node rather than narrowing the list it holds", async () => {
    const calls = stubNode(() => ({ body: libraryPayload() }));

    const wrapper = await mountAt(
      DocumentLibraryView,
      departmentLibraryLocation(),
    );

    await wrapper.get("select").setValue("draft");
    await flushPromises();

    expect(libraryReads(calls).at(-1)?.url).toBe(
      `http://node.test/api/organizations/${ORGANIZATION_ID}/documents?state=draft`,
    );
  });

  it("gives a reader the card list and the node's rendered document", async () => {
    stubNode(() => ({ body: readerLibraryPayload() }));

    const wrapper = await mountAt(
      DocumentLibraryView,
      departmentLibraryLocation(),
    );

    expect(wrapper.find(".staff-page").exists()).toBe(true);
    expect(wrapper.findAll(".staff-card").length).toBeGreaterThan(0);
    expect(wrapper.find("table").exists()).toBe(false);
    expect(wrapper.text()).not.toContain("Fragments");
    expect(wrapper.text()).not.toContain("New fragment");
    // Rendered by the node, with the fragment token resolved rather than shown.
    expect(wrapper.html()).toContain(
      "Treat people, radios, and camp spaces with care.",
    );
    expect(wrapper.text()).not.toContain("{{fragment:");
  });

  it("offers a row only the actions the node said the caller holds", async () => {
    stubNode(() => ({
      body: libraryPayload({
        documents: [
          documentPayload({ can_maintain: false }),
          procedurePayload(),
        ],
      }),
    }));

    const wrapper = await mountAt(
      DocumentLibraryView,
      departmentLibraryLocation(),
    );

    const rows = wrapper.findAll("tbody tr");
    const readOnly = rows.find((row) => row.text().includes("Volunteer Conduct"));
    const maintained = rows.find((row) => row.text().includes("Radio Checkout"));

    expect(readOnly?.findAll("button").length).toBe(0);
    expect(readOnly?.findAll("a").length).toBe(0);
    expect(maintained?.text()).toContain("Publish");
    expect(maintained?.text()).toContain("Archive");
  });

  it("asks for the reason the publish command requires and re-reads afterwards", async () => {
    let published = false;
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/publish-procedure-document")) {
        published = true;

        return {
          body: procedurePayload({ state: "published", state_label: "Published" }),
        };
      }

      return {
        body: libraryPayload({
          documents: [
            published
              ? procedurePayload({ state: "published", state_label: "Published" })
              : procedurePayload(),
          ],
        }),
      };
    });

    const wrapper = await mountAt(
      DocumentLibraryView,
      departmentLibraryLocation(),
    );

    await buttonWithText(wrapper, "Publish").trigger("click");
    await flushPromises();

    await wrapper.get(".documents__reason input").setValue("Ready for staff.");
    await wrapper.get(".documents__reason").trigger("submit");
    await flushPromises();

    const command = commandCalls(calls, "publish-procedure-document")[0];
    expect(command?.body).toEqual({
      document_id: RADIO_PROCEDURE_ID,
      reason: "Ready for staff.",
    });

    // The row is read back rather than patched in place.
    expect(libraryReads(calls).length).toBe(2);
    expect(wrapper.text()).toContain("Radio Checkout is now published.");
  });

  it("states an unreachable node instead of an organization with no documents", async () => {
    stubUnreachableNode();

    const wrapper = await mountAt(
      DocumentLibraryView,
      departmentLibraryLocation(),
    );

    expect(wrapper.text()).toContain("Unable to load documents.");
    expect(wrapper.text()).not.toContain("No documents match this filter.");
  });
});

describe("the document authoring form", () => {
  it("offers the scopes and Event Info placements the read returned", async () => {
    stubNode(() => ({ body: libraryPayload() }));

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.create",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
      },
    });

    const selects = wrapper.findAll("select");
    expect(selects[0]!.findAll("option").map((option) => option.text())).toEqual(
      ["Organization: Idaho Burners", "Department: Rangers"],
    );
    expect(selects[1]!.findAll("option").map((option) => option.text())).toEqual(
      [
        "Not shown on Event Info",
        ...EVENT_INFO_SECTIONS.map((section) => section.label),
      ],
    );
  });

  it("creates a policy through its command and opens the saved document", async () => {
    const created = documentPayload({
      state: "draft",
      state_label: "Draft",
      title: "Arrival Policy",
      slug: "arrival-policy",
      event_info_section: "arrival",
      event_info_section_label: "Arrival requirements",
    });
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/create-policy-document")) {
        return { status: 201, body: created };
      }

      // The form navigates to the edit route it just created, which reads the
      // document back rather than keeping the command's answer as the record.
      if (call.url.includes("/api/policy-documents/")) {
        return { body: created };
      }

      return { body: libraryPayload() };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.create",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
      },
    });

    const inputs = wrapper.findAll("input");
    await inputs[0]!.setValue("Arrival Policy");
    await inputs[1]!.setValue("arrival-policy");
    await wrapper.findAll("select")[1]!.setValue("arrival");
    await wrapper.get("textarea").setValue("# Arrival Policy\n\nArrive ready.");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(commandCalls(calls, "create-policy-document")[0]?.body).toEqual({
      organization_id: ORGANIZATION_ID,
      scope_type: "organization",
      scope_id: ORGANIZATION_ID,
      title: "Arrival Policy",
      slug: "arrival-policy",
      event_info_section: "arrival",
      markdown_source: "# Arrival Policy\n\nArrive ready.",
    });

    expect(wrapper.text()).toContain("Arrival Policy saved.");
    // The preview is the node's render of what it stored, not the browser's
    // reading of what was typed.
    expect(wrapper.html()).toContain(
      "Treat people, radios, and camp spaces with care.",
    );
  });

  it("clears an Event Info placement by sending null rather than omitting it", async () => {
    const calls = stubNode((call) => {
      if (call.url.endsWith("/commands/update-policy-document")) {
        return {
          body: documentPayload({
            event_info_section: null,
            event_info_section_label: null,
          }),
        };
      }

      if (call.url.includes("/api/policy-documents/")) {
        return { body: documentPayload() };
      }

      return { body: libraryPayload() };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
        artifactId: CONDUCT_POLICY_ID,
      },
    });

    await wrapper.findAll("select")[1]!.setValue("");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(
      commandCalls(calls, "update-policy-document")[0]?.body,
    ).toMatchObject({
      document_id: CONDUCT_POLICY_ID,
      event_info_section: null,
    });
  });

  it("shows the node's refusal instead of refusing the save a second time", async () => {
    stubNode((call) => {
      if (call.url.endsWith("/commands/update-policy-document")) {
        return {
          status: 422,
          body: {
            message: "The given data was invalid.",
            errors: {
              slug: ["Slug must use lowercase letters, numbers, and hyphens."],
            },
          },
        };
      }

      if (call.url.includes("/api/policy-documents/")) {
        return { body: documentPayload() };
      }

      return { body: libraryPayload() };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
        artifactId: CONDUCT_POLICY_ID,
      },
    });

    await wrapper.findAll("input")[1]!.setValue("Not A Slug");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Slug must use lowercase letters, numbers, and hyphens.",
    );
  });

  it("exports through a short-lived download URL rather than building a file", async () => {
    const issued =
      "http://node.test/downloads/policy-documents/x/export/markdown?actor=u1&expires=1&signature=abc";
    const openedUrls: string[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
      function click(this: HTMLAnchorElement): void {
        openedUrls.push(this.href);
      },
    );

    const calls = stubNode((call) => {
      if (call.url.endsWith("/export/markdown/download-url")) {
        return { body: { url: issued, expires_at: "2026-07-31T18:05:00+00:00" } };
      }

      if (call.url.includes("/api/policy-documents/")) {
        return { body: documentPayload() };
      }

      return { body: libraryPayload() };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
        artifactId: CONDUCT_POLICY_ID,
      },
    });

    await buttonWithText(wrapper, "Export Markdown").trigger("click");
    await flushPromises();

    expect(
      calls.some(
        (call) =>
          call.method === "POST" &&
          call.url ===
            `http://node.test/api/policy-documents/${CONDUCT_POLICY_ID}/export/markdown/download-url`,
      ),
    ).toBe(true);
    expect(openedUrls).toEqual([issued]);
  });

  it("does not open anything when the node refuses the export", async () => {
    const openedUrls: string[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
      function click(this: HTMLAnchorElement): void {
        openedUrls.push(this.href);
      },
    );

    stubNode((call) => {
      if (call.url.endsWith("/export/pdf/download-url")) {
        return {
          status: 403,
          body: { message: "You are not authorized to export this document." },
        };
      }

      if (call.url.includes("/api/policy-documents/")) {
        return { body: documentPayload() };
      }

      return { body: libraryPayload() };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
        artifactId: CONDUCT_POLICY_ID,
      },
    });

    await buttonWithText(wrapper, "Export PDF").trigger("click");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "You are not authorized to export this document.",
    );
    expect(openedUrls).toEqual([]);
  });

  it("reports published reference impact from the fragment's own read", async () => {
    const calls = stubNode((call) => {
      if (call.url.includes("/api/document-fragments/")) {
        return { body: fragmentPayload() };
      }

      return { body: libraryPayload() };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "fragment",
        artifactId: FRAGMENT_ID,
      },
    });

    expect(
      calls.some(
        (call) =>
          call.url ===
          `http://node.test/api/document-fragments/${FRAGMENT_ID}`,
      ),
    ).toBe(true);
    // Two documents reference it; one is published, so one version bumps.
    expect(wrapper.text()).toContain(
      "1 published document version bumps if Markdown changes.",
    );
    expect(wrapper.text()).toContain("Volunteer Conduct");
    expect(wrapper.text()).toContain("Radio Checkout");
    // A fragment has no publication lifecycle in Alpha 1 (data/API 11.5).
    expect(wrapper.findAll("button").map((button) => button.text())).not.toContain(
      "Publish",
    );
  });

  it("offers no publish, archive, or export on a document it may only read", async () => {
    stubNode((call) => {
      if (call.url.includes("/api/policy-documents/")) {
        return { body: documentPayload({ can_maintain: false }) };
      }

      return {
        body: libraryPayload({ access: { can_maintain: false, scopes: [] } }),
      };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
        artifactId: CONDUCT_POLICY_ID,
      },
    });

    // Publishing, archiving, and exporting are all maintain-scoped, so a
    // read-only document offers none of them rather than three refusals.
    const labels = wrapper.findAll("button").map((button) => button.text());
    expect(labels).not.toContain("Publish");
    expect(labels).not.toContain("Archive");
    expect(labels).not.toContain("Export Markdown");
    expect(labels).not.toContain("Export PDF");
    expect(wrapper.text()).toContain(
      "Document authoring requires organizer, department lead, or team lead authority",
    );
  });

  it("fails closed when the node refuses the document the URL names", async () => {
    stubNode((call) => {
      if (call.url.includes("/api/policy-documents/")) {
        return {
          status: 403,
          body: { message: "You do not have permission to view this document." },
        };
      }

      return { body: libraryPayload({ access: { can_maintain: false, scopes: [] } }) };
    });

    const wrapper = await mountAt(DocumentEditView, {
      name: "events.departments.documents.edit",
      params: {
        eventId: EVENT_ID,
        departmentId: DEPARTMENT_ID,
        artifactKind: "policy",
        artifactId: CONDUCT_POLICY_ID,
      },
    });

    expect(wrapper.text()).toContain(
      "You do not have permission to view this document.",
    );
    expect(wrapper.find("textarea").exists()).toBe(false);
  });
});
