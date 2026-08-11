// The Directory surface against a stubbed node (M18.75; DIR-002, DIR-003,
// DIR-005, DIR-009 through DIR-016, DIR-029; UI contract 12.3, 19D).
//
// What is under test is the seam and the contract's presentation rules: the
// chart opens collapsed to departments, expands by touch through real buttons,
// renders a person entry from the projection and nothing else, draws an empty
// branch as itself with no count and no marker, and — where the organization
// has the Directory disabled — is absent from the menu, the palette, and the
// route alike, with not-found copy rather than an explanation.
//
// Widths are covered structurally here and by hand in QA-DIR-01: jsdom lays
// nothing out, so what these tests hold is the shape that makes every width
// work — a vertically scrolling disclosure list, no box-and-line diagram, and
// no hover-only control anywhere (19D.9).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { useWorkflowLinks } from "@/components/workflowLinks";
import {
  directoryPresence,
  refreshDirectoryPresence,
  resetDirectoryPresence,
} from "@/directory/directoryModel";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSessionFixture";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import DirectoryView from "@/views/DirectoryView.vue";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;

interface NodeCall {
  readonly url: string;
  readonly method: string;
}

function chartPayload(overrides: Record<string, unknown> = {}) {
  return {
    context: {
      scope: "event",
      organization_id: LOCAL_FIELD_ORGANIZATION_ID,
      organization_label: "Northwood Collective",
      event_id: EVENT_ID,
      event_label: "Local Field Event",
    },
    departments: [
      {
        id: "dept-organizers",
        name: "Organizers",
        is_organizers: true,
        leads: [],
        teams: [
          {
            id: "team-core",
            name: "Core",
            leads: [],
            members: ["staff-olive", "staff-tess"],
          },
        ],
        prospectives: [],
      },
      {
        id: "dept-rangers",
        name: "Rangers",
        is_organizers: false,
        leads: ["staff-dana"],
        teams: [
          {
            id: "team-dirt",
            name: "Dirt",
            leads: ["staff-tess"],
            members: ["staff-vera"],
          },
          { id: "team-empty", name: "Zero Crew", leads: [], members: [] },
        ],
        prospectives: ["staff-pia"],
      },
      {
        id: "dept-empty",
        name: "Airport",
        is_organizers: false,
        leads: [],
        teams: [],
        prospectives: [],
      },
    ],
    people: [
      {
        id: "staff-olive",
        handle: "Olive",
        profile_picture_url: null,
        years_of_service: 6,
        locations: [
          {
            department_id: "dept-organizers",
            team_id: "team-core",
            kind: "team_member",
            status: "active",
          },
        ],
      },
      {
        id: "staff-dana",
        handle: "Dana",
        profile_picture_url: null,
        years_of_service: 4,
        locations: [
          {
            department_id: "dept-rangers",
            team_id: null,
            kind: "department_lead",
            status: "active",
          },
        ],
      },
      {
        id: "staff-tess",
        handle: "Tess",
        profile_picture_url: null,
        years_of_service: 1,
        locations: [
          {
            department_id: "dept-rangers",
            team_id: "team-dirt",
            kind: "team_lead",
            status: "active",
          },
          {
            department_id: "dept-organizers",
            team_id: "team-core",
            kind: "team_member",
            status: "active",
          },
        ],
      },
      {
        id: "staff-vera",
        handle: "Vera",
        profile_picture_url: null,
        years_of_service: 0,
        locations: [
          {
            department_id: "dept-rangers",
            team_id: "team-dirt",
            kind: "team_member",
            status: "active",
          },
        ],
      },
      {
        id: "staff-pia",
        handle: "Pia",
        profile_picture_url: null,
        years_of_service: 2,
        locations: [
          {
            department_id: "dept-rangers",
            team_id: null,
            kind: "prospective",
            status: "prospective",
          },
        ],
      },
    ],
    ...overrides,
  };
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

const mounted: VueWrapper[] = [];

beforeEach(() => {
  clearOfflineReadSet();
  resetDirectoryPresence();
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
  resetDirectoryPresence();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

async function mountView(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "directory" });
  await router.isReady();

  const wrapper = mount(DirectoryView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);

  await flushPromises();

  return wrapper;
}

describe("the Directory chart", () => {
  it("opens collapsed to departments, with the Organizers Department first", async () => {
    stubNode(() => ({ body: chartPayload() }));

    const wrapper = await mountView();
    const text = wrapper.get('[data-testid="directory-chart"]').text();

    // Every department heading is on screen (DIR-016)...
    expect(text).toContain("Organizers");
    expect(text).toContain("Rangers");
    expect(text).toContain("Airport");

    // ...and nothing beneath any of them is (collapsed means collapsed).
    expect(text).not.toContain("Dirt");
    expect(text).not.toContain("Tess");
    expect(text).not.toContain("Prospectives");

    // Organizers first (DIR-009).
    const departmentButtons = wrapper.findAll(
      ".directory__disclosure[data-department-id]",
    );
    expect(departmentButtons[0].text()).toContain("Organizers");
  });

  it("expands by touch through real buttons, keyboard-reachable and never hover-only", async () => {
    stubNode(() => ({ body: chartPayload() }));

    const wrapper = await mountView();

    // Disclosure is a real <button> with aria-expanded: reachable by keyboard
    // by construction, activated by click and by Enter alike, and there is no
    // hover handler anywhere on the surface (DIR-003, 19D.4).
    const rangers = wrapper.get('[data-department-id="dept-rangers"]');
    expect(rangers.element.tagName).toBe("BUTTON");
    expect(rangers.attributes("aria-expanded")).toBe("false");

    await rangers.trigger("click");
    expect(rangers.attributes("aria-expanded")).toBe("true");

    // Department order within: leads, then teams, then Prospectives (DIR-011).
    expect(wrapper.text()).toContain("Department leads");
    expect(wrapper.text()).toContain("Dana");
    expect(wrapper.text()).toContain("Prospectives");
    expect(wrapper.text()).toContain("Pia");

    // Team branches are their own disclosure (DIR-016): people inside a team
    // stay unrendered until it is opened.
    expect(wrapper.text()).not.toContain("Tess");

    await wrapper.get('[data-team-id="team-dirt"]').trigger("click");

    // Team order: leads above members, and the lead is not repeated as an
    // ordinary member of their own team (DIR-012).
    const dirtText = wrapper.text();
    expect(dirtText).toContain("Team leads");
    expect(dirtText).toContain("Tess");
    expect(dirtText).toContain("Vera");
    expect(dirtText.indexOf("Tess")).toBeLessThan(dirtText.indexOf("Vera"));
  });

  it("renders a person entry from the projection and nothing else", async () => {
    stubNode(() => ({ body: chartPayload() }));

    const wrapper = await mountView();
    await wrapper.get('[data-department-id="dept-rangers"]').trigger("click");
    await wrapper.get('[data-team-id="team-dirt"]').trigger("click");

    const entry = wrapper.get('[data-staff-id="staff-tess"]');

    // Picture (the lettermark, from the handle), handle, location, years of
    // service (DIR-029) — and that is the complete list.
    expect(entry.text()).toContain("Tess");
    expect(entry.text()).toContain("1 year of service");
    expect(entry.text()).toContain("Rangers → Dirt → Team Lead");
    expect(entry.find(".directory-person__lettermark").text()).toBe("TE");

    // No contact control, no messaging control, no action of any kind
    // (19D.5): a person entry carries no interactive element at all.
    expect(entry.findAll("a")).toHaveLength(0);
    expect(entry.findAll("button")).toHaveLength(0);
    expect(wrapper.html()).not.toContain("mailto:");
    expect(wrapper.html()).not.toContain("tel:");
  });

  it("renders an empty branch as itself, with no count and no marker", async () => {
    stubNode(() => ({ body: chartPayload() }));

    const wrapper = await mountView();

    // The empty department renders (DIR-015)...
    await wrapper.get('[data-department-id="dept-empty"]').trigger("click");

    // ...and an empty team inside a populated department renders too.
    await wrapper.get('[data-department-id="dept-rangers"]').trigger("click");
    const zeroCrew = wrapper.get('[data-team-id="team-empty"]');
    expect(zeroCrew.text()).toContain("Zero Crew");

    await zeroCrew.trigger("click");

    // No count, no lock, no "hidden" marker, no explanatory copy anywhere
    // (DIR-015, 19D.3).
    const text = wrapper.text();
    expect(text).not.toMatch(/hidden/i);
    expect(text).not.toMatch(/restricted/i);
    expect(text).not.toMatch(/withheld/i);
    expect(text).not.toMatch(/\d+\s+members?\b/i);
    expect(text).not.toContain("🔒");
  });

  it("is a vertically scrolling disclosure list rather than a panned diagram", async () => {
    stubNode(() => ({ body: chartPayload() }));

    const wrapper = await mountView();

    // The structural half of 19D.9, at every width: the chart is nested lists
    // with disclosure buttons — no SVG, no canvas, and no horizontally
    // scrolled container for the tree to be panned inside.
    expect(wrapper.get('[data-testid="directory-chart"]').element.tagName).toBe("UL");
    expect(wrapper.find("svg").exists()).toBe(false);
    expect(wrapper.find("canvas").exists()).toBe(false);
    expect(wrapper.html()).not.toContain("overflow-x");
  });
});

describe("search beside the chart and filtering (M18.76)", () => {
  const TESS_ROW = {
    staff_id: "staff-tess",
    handle: "Tess",
    breadcrumb: "Rangers → Dirt → Team Lead",
    location: {
      department_id: "dept-rangers",
      team_id: "team-dirt",
      kind: "team_lead",
    },
  };

  function stubNodeWithSearch(rows: readonly Record<string, unknown>[]): void {
    stubNode((call) =>
      call.url.includes("/directory/search")
        ? { body: { results: rows } }
        : { body: chartPayload() },
    );
  }

  it("stays usable while the chart is browsed", async () => {
    stubNodeWithSearch([TESS_ROW]);

    const wrapper = await mountView();

    await wrapper.get("#directory-search").setValue("Te");
    await flushPromises();

    // A result row, carrying the handle and the breadcrumb (DIR-034).
    expect(wrapper.get(".directory__result").text()).toContain("Tess");
    expect(wrapper.get(".directory__result").text()).toContain(
      "Rangers → Dirt → Team Lead",
    );

    // Browsing the chart does not dismiss, replace, or narrow the search
    // interface (DIR-031): no separate page, no tab, no mode.
    await wrapper.get('[data-department-id="dept-rangers"]').trigger("click");

    expect(
      (wrapper.get("#directory-search").element as HTMLInputElement).value,
    ).toBe("Te");
    expect(wrapper.find(".directory__result").exists()).toBe(true);
    expect(wrapper.find('[data-testid="directory-chart"]').exists()).toBe(true);
  });

  it("selecting a result expands the branches, scrolls to the row's own node, and highlights every occurrence", async () => {
    stubNodeWithSearch([TESS_ROW]);

    const scrolledTo: Element[] = [];
    Object.defineProperty(Element.prototype, "scrollIntoView", {
      configurable: true,
      writable: true,
      value(this: Element) {
        scrolledTo.push(this);
      },
    });

    const wrapper = await mountView();

    await wrapper.get("#directory-search").setValue("Tess");
    await flushPromises();
    await wrapper.get(".directory__result").trigger("click");
    await flushPromises();

    // Every branch holding an authorized occurrence is open: her Rangers team
    // lead placement and her Organizers membership alike (DIR-035).
    expect(
      wrapper.get('[data-department-id="dept-rangers"]').attributes("aria-expanded"),
    ).toBe("true");
    expect(
      wrapper.get('[data-department-id="dept-organizers"]').attributes("aria-expanded"),
    ).toBe("true");

    // Both occurrences are highlighted, with a marker and accessible text
    // rather than color alone (section 20).
    const highlighted = wrapper.findAll('[data-highlighted="true"]');
    expect(highlighted).toHaveLength(2);
    expect(highlighted[0].text()).toContain("Search match");

    // The scroll goes to the selected row's own node — open question 38, as
    // settled: the row names one location, and that is where the chart goes.
    expect(
      scrolledTo.some(
        (element) => element.getAttribute("data-team-id") === "team-dirt",
      ),
    ).toBe(true);

    // And search is left in place (DIR-035): same query, same results.
    expect(
      (wrapper.get("#directory-search").element as HTMLInputElement).value,
    ).toBe("Tess");
    expect(wrapper.find(".directory__result").exists()).toBe(true);
  });

  it("clears the previous highlight on the next search", async () => {
    stubNodeWithSearch([TESS_ROW]);

    const wrapper = await mountView();

    await wrapper.get("#directory-search").setValue("Tess");
    await flushPromises();
    await wrapper.get(".directory__result").trigger("click");
    await flushPromises();

    expect(wrapper.findAll('[data-highlighted="true"]').length).toBeGreaterThan(0);

    await wrapper.get("#directory-search").setValue("Vera");
    await flushPromises();

    expect(wrapper.findAll('[data-highlighted="true"]')).toHaveLength(0);
  });

  it("offers no dropdown as a filter's primary interaction", async () => {
    stubNodeWithSearch([]);

    const wrapper = await mountView();
    const tools = wrapper.get(".directory__tools");

    // Chips — immediately visible, touch-first buttons carrying their own
    // pressed state (19D.7) — and not one select element anywhere in the
    // filtering interface.
    expect(tools.findAll("select")).toHaveLength(0);

    const chips = tools.findAll(".directory__chip");
    expect(chips.length).toBeGreaterThan(0);

    for (const chip of chips) {
      expect(chip.element.tagName).toBe("BUTTON");
      expect(chip.attributes("aria-pressed")).toBeDefined();
    }
  });

  it("counts only visible people when a filter narrows the chart", async () => {
    stubNodeWithSearch([]);

    const wrapper = await mountView();

    // No filter, no count: a number with nothing narrowed would just be the
    // population size.
    expect(wrapper.text()).not.toContain("shown.");

    const roleChip = wrapper
      .findAll(".directory__chip")
      .find((chip) => chip.text() === "Team Lead");
    await roleChip!.trigger("click");

    // One team lead is visible to this viewer, and the count counts exactly
    // the people the filtered chart presents (DIR-036).
    expect(wrapper.text()).toContain("1 person shown.");

    // The status facet narrows the same way, from the placement's own status.
    await roleChip!.trigger("click");

    const statusChip = wrapper
      .findAll(".directory__chip")
      .find((chip) => chip.text() === "Prospective");
    await statusChip!.trigger("click");

    expect(wrapper.text()).toContain("1 person shown.");
  });
});

describe("a disabled Directory (DIR-005)", () => {
  it("renders not-found copy on the route, with no explanation", async () => {
    stubNode(() => ({ status: 404, body: { message: "" } }));

    const wrapper = await mountView();

    expect(wrapper.text()).toContain("Page not found");

    // Absence is never explained (19D.2): no empty state, no mention of the
    // feature being disabled or configurable.
    expect(wrapper.text()).not.toMatch(/disabled/i);
    expect(wrapper.text()).not.toMatch(/switched off/i);
    expect(wrapper.text()).not.toMatch(/organization has/i);
  });

  it("is absent from the Workflows menu and the palette until a live answer offers it, and stays absent when the node answers 404", async () => {
    // Nothing has confirmed the organization offers a Directory: no entry.
    const links = useWorkflowLinks();
    expect(links.value.map((link) => link.label)).not.toContain("Directory");

    // The node says the organization has none: still no entry.
    stubNode(() => ({ status: 404, body: { message: "" } }));
    await refreshDirectoryPresence();
    expect(links.value.map((link) => link.label)).not.toContain("Directory");
    expect(directoryPresence.enabled).toBe(false);

    // The node offers the chart: the entry appears, for the same session with
    // no capability involved (DIR-002, DIR-024).
    stubNode(() => ({ body: chartPayload() }));
    await refreshDirectoryPresence();
    expect(links.value.map((link) => link.label)).toContain("Directory");
  });
});
