// The two export surfaces against a stubbed node (M18.26; REPORT-014,
// REPORT-015; REPORT-006 through REPORT-010; CLIENT-005, CLIENT-019,
// CLIENT-020, CLIENT-024).
//
// REPORT-014 asks for two surfaces and names three properties they owe: only
// the exports the actor is authorized to run, the scope and the excluded fields
// stated before generation, and — through REPORT-015 — a short-lived scoped URL
// rather than a credentialed link. The cases below are those three, twice, plus
// the one thing that separates the surfaces: the organizer's runs at the
// caller's own event-wide scope and the department's names its department in
// every request it makes.
//
// No server runs for any of it, which is the requirement (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_ORGANIZATION_ID,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  CAPABILITY_REPORTS_HOURS_WORKED_EXPORT,
  CAPABILITY_REPORTS_SHIFT_ROSTER_EXPORT,
} from "@/session/permissionCodes";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import DepartmentExportsView from "@/views/DepartmentExportsView.vue";
import OrganizerExportsView from "@/views/OrganizerExportsView.vue";

const EVENT_ID = "11111111-1111-4111-8111-111111111111";
const GATE_DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.gate;
const RANGERS_DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

function exportUrlPath(report: string): string {
  return `/api/events/${EVENT_ID}/exports/${report}/download-url`;
}

const ISSUED_URL = `http://node.test/downloads/events/${EVENT_ID}/exports/hours-worked?actor=u1&expires=1&signature=abc`;

/** One request this client made, as the assertions read it. */
interface NodeCall {
  readonly url: string;
  readonly method: string;
  readonly body: Record<string, unknown> | null;
}

/**
 * A node that answers the department list and the export URL requests.
 *
 * Everything else answers `{}`, so a surface that asked for something it should
 * not have asked for shows up as an unexpected entry in `calls` rather than as
 * an unhandled rejection.
 */
function stubNode(
  reply: (
    call: NodeCall,
  ) => { readonly status?: number; readonly body: unknown } | null = () => null,
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

      const answered = reply(call);

      if (answered !== null) {
        return new Response(JSON.stringify(answered.body), {
          status: answered.status ?? 200,
          headers: { "content-type": "application/json" },
        });
      }

      if (call.url.includes("/departments?status=active")) {
        return new Response(
          JSON.stringify({
            organization_id: LOCAL_FIELD_ORGANIZATION_ID,
            departments: [
              departmentPayload(RANGERS_DEPARTMENT_ID, "Rangers", "RANGERS"),
              departmentPayload(GATE_DEPARTMENT_ID, "Gate", "GATE"),
            ],
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        );
      }

      if (call.url.includes("/download-url")) {
        return new Response(
          JSON.stringify({
            url: ISSUED_URL,
            expires_at: "2026-08-05T18:05:00+00:00",
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        );
      }

      return new Response(JSON.stringify({}), {
        status: 200,
        headers: { "content-type": "application/json" },
      });
    }),
  );

  return calls;
}

function departmentPayload(id: string, name: string, code: string) {
  return {
    id,
    organization_id: LOCAL_FIELD_ORGANIZATION_ID,
    name,
    code,
    description: null,
    archived_at: null,
  };
}

/** Every URL the browser was sent to, so a refusal that opens a tab is visible. */
function recordNavigations(): string[] {
  const opened: string[] = [];

  vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
    function click(this: HTMLAnchorElement): void {
      opened.push(this.href);
    },
  );

  return opened;
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
  clearOfflineReadSet();
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

async function mountOrganizerExports(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({ name: "organizer.exports.index" });
  await router.isReady();

  const wrapper = mount(OrganizerExportsView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);
  await flushPromises();

  return wrapper;
}

/**
 * The department surface, entered the way a person enters it.
 *
 * Through the route rather than by selecting the department first, because the
 * router's `beforeEnter` recording the route's department is half of what makes
 * a deep link land in the right one.
 */
async function mountDepartmentExports(
  departmentId: string = RANGERS_DEPARTMENT_ID,
): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push({
    name: "events.departments.exports.index",
    params: { eventId: EVENT_ID, departmentId },
  });
  await router.isReady();

  const wrapper = mount(DepartmentExportsView, {
    global: { plugins: [router] },
  }) as VueWrapper;

  mounted.push(wrapper);
  await flushPromises();

  return wrapper;
}

function exportButtons(wrapper: VueWrapper) {
  return wrapper
    .findAll("button")
    .filter((button) => (button.attributes("aria-label") ?? "").startsWith("Export "));
}

function exportButton(wrapper: VueWrapper, label: string) {
  return wrapper
    .findAll("button")
    .find((button) => button.attributes("aria-label") === `Export ${label}`);
}

describe("the organizer export surface", () => {
  it("offers every Alpha 1 export and states each one before it is generated", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
    stubNode();

    const wrapper = await mountOrganizerExports();
    const text = wrapper.text();

    // REPORT-001 through REPORT-005, all five reachable from one surface, which
    // is the whole of what M18.26 adds over the single entry point M16.22 had.
    expect(
      exportButtons(wrapper).map((button) => button.attributes("aria-label")),
    ).toEqual([
      "Export Credential eligibility",
      "Export Shift roster",
      "Export Staff contact list",
      "Export Hours worked",
      "Export Credits earned",
    ]);

    // REPORT-014: scope and excluded fields readable without opening the file.
    expect(text).toContain("Local Field Event");
    expect(text).toContain("every department working the event");
    expect(text).toContain("Emergency contacts");
    expect(text).toContain("Phone numbers");
    // REPORT-009 and REPORT-010 as the condition they are rather than as a
    // promise this surface cannot keep: whether the columns come back is the
    // node's answer from the scope it resolves.
    expect(text).toContain("emergency_contact_name");
    expect(text).toContain(
      "Included only when every exported row belongs to a department you hold a department role in",
    );
  });

  it("asks the node for a short-lived URL, navigates to it, and sends no narrowing", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    const opened = recordNavigations();
    const calls = stubNode();
    const wrapper = await mountOrganizerExports();

    await exportButton(wrapper, "Hours worked")!.trigger("click");
    await flushPromises();

    const issued = calls.filter((call) => call.url.includes("/download-url"));

    // CLIENT-019: the credential rides on the request that asks for the URL,
    // never in the link that comes back.
    expect(issued).toHaveLength(1);
    expect(issued[0]?.method).toBe("POST");
    expect(issued[0]?.url).toContain(exportUrlPath("hours-worked"));
    // REPORT-006: an organizer's own scope is the whole event, so there is
    // nothing to narrow to unless somebody picked one.
    expect(issued[0]?.body).toEqual({});

    expect(opened).toEqual([ISSUED_URL]);
    expect(wrapper.text()).toContain("Hours worked is downloading.");
  });

  it("runs the export the button was labelled with", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    const calls = stubNode();
    const wrapper = await mountOrganizerExports();

    await exportButton(wrapper, "Credits earned")!.trigger("click");
    await flushPromises();

    // A list of five buttons is exactly where a surface runs the wrong one.
    expect(
      calls.find((call) => call.url.includes("/download-url"))?.url,
    ).toContain(exportUrlPath("credits-earned"));
  });

  it("narrows to one department without widening what the caller may reach", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    const calls = stubNode();
    const wrapper = await mountOrganizerExports();

    await wrapper.find("#export-department").setValue(RANGERS_DEPARTMENT_ID);
    await flushPromises();

    // The scope statement moves with the picker, so what the file will cover is
    // still readable before it is generated (REPORT-014).
    expect(wrapper.text()).toContain("narrowed to Rangers");

    await exportButton(wrapper, "Staff contact list")!.trigger("click");
    await flushPromises();

    expect(
      calls.find((call) => call.url.includes("/download-url"))?.body,
    ).toEqual({ department_id: RANGERS_DEPARTMENT_ID });
  });

  it("leaves an export the caller cannot run off the page rather than disabling it", async () => {
    // CLIENT-005, and the case REPORT-014 names: the surface offers only the
    // exports this actor is authorized to run. The five codes are granted
    // separately, so holding one is not holding five.
    const document = localFieldSessionDocument();

    installClientSession(
      localFieldSessionDocument({
        roles: document.roles.map((role) =>
          role.role_code === "organizer"
            ? { ...role, capabilities: [CAPABILITY_REPORTS_HOURS_WORKED_EXPORT] }
            : role,
        ),
      }),
      "network",
    );
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
    stubNode();

    const wrapper = await mountOrganizerExports();

    expect(
      exportButtons(wrapper).map((button) => button.attributes("aria-label")),
    ).toEqual(["Export Hours worked"]);
    expect(wrapper.text()).not.toContain("Credential eligibility");
    expect(wrapper.text()).not.toContain("Credits earned");
  });

  it("offers nothing to a department lead, whose authority is not event-wide", async () => {
    // REPORT-007. A lead holds all five codes and none of them reach past their
    // own department, so a page promising the event is not theirs to stand on.
    selectSessionDepartment(RANGERS_DEPARTMENT_ID);
    stubNode();

    const wrapper = await mountOrganizerExports();

    expect(exportButtons(wrapper)).toEqual([]);
    expect(wrapper.text()).toContain(
      "Event-wide exports require organizer authority",
    );
  });

  it("prints the node's refusal and opens nothing", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    const opened = recordNavigations();
    stubNode((call) =>
      call.url.includes("/download-url")
        ? {
            status: 403,
            body: {
              message:
                "You do not have permission to export hours worked for this event.",
            },
          }
        : null,
    );

    const wrapper = await mountOrganizerExports();

    await exportButton(wrapper, "Hours worked")!.trigger("click");
    await flushPromises();

    // CLIENT-020: authorization is decided at issuance, so a refused export is
    // a sentence on the page rather than a tab onto an error document.
    expect(wrapper.find('[role="alert"]').text()).toContain(
      "You do not have permission to export hours worked for this event.",
    );
    expect(opened).toEqual([]);
  });

  it("refuses to run while the device has no network rather than queueing it", async () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);
    setNavigatorOnline(false);

    const calls = stubNode();
    const wrapper = await mountOrganizerExports();

    expect(wrapper.text()).toContain(
      "Exports are generated by the node and require a server connection.",
    );

    const button = exportButton(wrapper, "Hours worked")!;
    expect(button.attributes("disabled")).toBeDefined();

    await button.trigger("click");
    await flushPromises();

    // An export is not a command; there is nothing for the outbox to replay.
    expect(calls.filter((call) => call.url.includes("/download-url"))).toEqual(
      [],
    );
  });
});

describe("the department export surface", () => {
  it("names its department in every export it runs", async () => {
    const calls = stubNode();
    const wrapper = await mountDepartmentExports();

    expect(wrapper.text()).toContain("Rangers only, within Local Field Event");

    await exportButton(wrapper, "Shift roster")!.trigger("click");
    await flushPromises();

    // REPORT-007: the scope this page states is the scope its request carries,
    // rather than a sentence hoping the node agrees.
    const issued = calls.filter((call) => call.url.includes("/download-url"));
    expect(issued).toHaveLength(1);
    expect(issued[0]?.url).toContain(exportUrlPath("shift-roster"));
    expect(issued[0]?.body).toEqual({ department_id: RANGERS_DEPARTMENT_ID });
  });

  it("states the exclusions the export carries rather than the ones the role would allow", async () => {
    stubNode();

    const wrapper = await mountDepartmentExports();
    const text = wrapper.text();

    // REPORT-008 holds for a department lead too: the roster excludes phone
    // numbers and emergency contacts whoever runs it, and the contact list
    // beside it may carry both (REPORT-009).
    expect(text).toContain("Shift roster");
    expect(text).toContain("Phone numbers, Emergency contacts");
    expect(text).toContain("staff_phone");
    expect(text).toContain("emergency_contact_phone");
    expect(text).toContain("exported as Department Lead");
  });

  it("leaves an export the caller cannot run off the page rather than disabling it", async () => {
    const document = localFieldSessionDocument();

    installClientSession(
      localFieldSessionDocument({
        roles: document.roles.map((role) =>
          role.role_code === "department_lead"
            ? { ...role, capabilities: [CAPABILITY_REPORTS_SHIFT_ROSTER_EXPORT] }
            : role,
        ),
      }),
      "network",
    );
    stubNode();

    const wrapper = await mountDepartmentExports();

    expect(
      exportButtons(wrapper).map((button) => button.attributes("aria-label")),
    ).toEqual(["Export Shift roster"]);
    expect(wrapper.text()).not.toContain("Staff contact list");
  });

  it("offers nothing to an organizer, whose authority is not this department's", async () => {
    stubNode();

    const wrapper = await mountDepartmentExports(
      LOCAL_FIELD_DEPARTMENT_IDS.organizer,
    );

    expect(exportButtons(wrapper)).toEqual([]);
    expect(wrapper.text()).toContain(
      "Department exports require a department role carrying an export capability here",
    );
  });

  it("offers nothing in a department the user holds no export grant in", async () => {
    stubNode();

    const wrapper = await mountDepartmentExports(GATE_DEPARTMENT_ID);

    expect(exportButtons(wrapper)).toEqual([]);
  });
});
