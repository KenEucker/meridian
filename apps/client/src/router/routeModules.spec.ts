import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { createMemoryHistory, createRouter, type Router } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  moduleGateRedirect,
  MODULE_UNAVAILABLE_ROUTE,
  routeModule,
  routeModuleActive,
  ROUTE_MODULES,
} from "@/router/routeModules";
import { registerNavigationGuards, routes } from "@/router";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  localFieldOrganizationsWithout,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import {
  MODULE_DOCUMENTS,
  MODULE_EQUIPMENT,
  MODULE_INCIDENT_MANAGEMENT,
  MODULE_KEYS,
  MODULE_QUALIFICATIONS,
  MODULE_SCHEDULING,
  type ModuleKey,
} from "@/session/sessionModules";

/*
 * A disabled module has no reachable route (M19.16; MOD-012, MOD-013, MOD-015;
 * technical spec 15A.5, 15A.8).
 *
 * Navigation hiding an entry is half the requirement and the weaker half: an
 * address can be typed, bookmarked, or sent in a message, and the reader
 * following one is exactly the person owed an explanation. These cases drive
 * the application's own guard on a real router, so what is exercised is the
 * rule the client installs rather than a second copy of it.
 *
 * It is presentation throughout. The node refuses the reads behind every one of
 * these surfaces on its own gate (MOD-012), and nothing here is what keeps an
 * inactive module's records away from anybody.
 */

const DEPARTMENT = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

function establish(...inactive: readonly ModuleKey[]): void {
  installClientSession(
    localFieldSessionDocument({
      organizations: localFieldOrganizationsWithout(...inactive),
    }),
    "network",
  );
  selectSessionDepartment(DEPARTMENT);
}

function guardedRouter(): Router {
  const router = createRouter({ history: createMemoryHistory(), routes });

  registerNavigationGuards(router, "field");

  return router;
}

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  configureMeridianApi(null);
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("the route ownership table", () => {
  it("names only routes the router actually serves", () => {
    /*
     * A declaration for a route that no longer exists gates nothing and reads
     * like it does, which is the failure mode a table separated from its routes
     * has. The reverse direction — a module-owned route with no declaration —
     * is the case below.
     */
    const served = new Set(
      routes
        .map((route) => route.name)
        .filter((name): name is string => typeof name === "string"),
    );

    for (const name of Object.keys(ROUTE_MODULES)) {
      expect(served.has(name)).toBe(true);
    }
  });

  it("declares an owner for every route whose address is a module's", () => {
    /*
     * The client's answer to the server's `ModuleRouteCoverageTest`. It cannot
     * read middleware, so it reads addresses: a path under `/shifts`,
     * `/documents`, `/trainings`, `/equipment`, `/deployments`, or `/ims` is
     * module-owned by construction, and one that reached the router without a
     * declaration would be reachable in an organization that does not run it.
     *
     * The two exceptions are named rather than pattern-matched, because both
     * are deliberate: the acknowledgment ledger and the staff document library
     * live at `/staff/...` and `/signup/...` and are declared, and the
     * department operations surfaces that *read* module data are core (MOD-019).
     */
    const OWNED_PATH_SEGMENTS = [
      "/shifts",
      "/documents",
      "/acknowledgments",
      "/trainings",
      "/equipment",
      "/deployments",
      "/credentials",
      "/waivers",
      "/ims",
      "/field-reports",
    ];

    const owned = routes.filter(
      (route) =>
        typeof route.name === "string" &&
        route.component !== undefined &&
        OWNED_PATH_SEGMENTS.some((segment) => route.path.includes(segment)),
    );

    // A pattern list that matched nothing would pass the assertion below while
    // checking nothing, which is the one way this test could rot silently.
    expect(owned.length).toBeGreaterThan(20);

    expect(
      owned.filter((route) => routeModule(route.name) === null).map((route) => route.name),
    ).toEqual([]);
  });

  it("names a module in the catalogue for every route it owns", () => {
    for (const module of Object.values(ROUTE_MODULES)) {
      expect(MODULE_KEYS).toContain(module);
    }
  });

  it("treats an undeclared route as core", () => {
    // The safe direction: forgetting a declaration leaves a page reachable and
    // refused by the node, rather than making one disappear.
    expect(routeModule("events.departments.logistics")).toBeNull();
    expect(routeModule("staff.me")).toBeNull();
    expect(routeModule(undefined)).toBeNull();
    expect(routeModule("a.route.nobody.wrote")).toBeNull();
  });
});

describe("the module gate on a navigation", () => {
  it("sends an address owned by an inactive module to the absence surface", () => {
    establish(MODULE_SCHEDULING);

    expect(moduleGateRedirect({ name: "staff.shifts.index" })).toEqual({
      name: MODULE_UNAVAILABLE_ROUTE,
      params: { moduleKey: MODULE_SCHEDULING },
    });
  });

  it("leaves every other address alone", () => {
    establish(MODULE_SCHEDULING);

    expect(moduleGateRedirect({ name: "events.departments.logistics" })).toBeNull();
    expect(moduleGateRedirect({ name: "staff.documents.index" })).toBeNull();
    expect(moduleGateRedirect({ name: "home" })).toBeNull();
  });

  it("decides nothing for a client that holds no session", () => {
    // Nothing is known about any organization, so nothing is gated. The client
    // is sent to sign in by the guard ahead of this one.
    clearClientSession();

    expect(moduleGateRedirect({ name: "staff.shifts.index" })).toBeNull();
  });

  it("asks the organization the address names rather than the one on screen", () => {
    /*
     * The guard runs before the `beforeEnter` that records the department, so a
     * deep link into another organization would otherwise be answered by the
     * module state of the one the client was last working in — which is how a
     * link somebody was sent turns into a wrong sentence about their employer.
     */
    const document = localFieldSessionDocument();
    const other = "org-cascadia-collective";

    installClientSession(
      localFieldSessionDocument({
        organizations: [
          ...localFieldOrganizationsWithout(),
          {
            id: other,
            name: "Cascadia Collective",
            slug: "cascadia-collective",
            status: "approved",
            archived_at: null,
            modules: MODULE_KEYS.filter((module) => module !== MODULE_EQUIPMENT),
          },
        ],
        departments: [
          ...document.departments,
          {
            id: "dept-cascadia",
            organization_id: other,
            name: "Cascadia Rangers",
            code: "CASC",
            membership_status: "active",
            archived_at: null,
          },
        ],
      }),
      "network",
    );
    selectSessionDepartment(DEPARTMENT);

    // Rangers runs Equipment; the Cascadia department in the address does not.
    expect(
      moduleGateRedirect({
        name: "events.departments.equipment.index",
        params: { departmentId: DEPARTMENT },
      }),
    ).toBeNull();
    expect(
      moduleGateRedirect({
        name: "events.departments.equipment.index",
        params: { departmentId: "dept-cascadia" },
      }),
    ).toEqual({
      name: MODULE_UNAVAILABLE_ROUTE,
      params: { moduleKey: MODULE_EQUIPMENT },
    });
  });
});

describe("a route owned by a module the organization does not run", () => {
  it("is unreachable by address", async () => {
    establish(MODULE_SCHEDULING);

    const router = guardedRouter();

    await router.push("/staff/shifts");

    expect(router.currentRoute.value.name).toBe(MODULE_UNAVAILABLE_ROUTE);
    expect(router.currentRoute.value.params.moduleKey).toBe(MODULE_SCHEDULING);
  });

  it("is unreachable by name, params and all", async () => {
    establish(MODULE_QUALIFICATIONS);

    const router = guardedRouter();

    await router.push({
      name: "events.departments.trainings.index",
      params: { eventId: LOCAL_FIELD_FIXTURE.eventId, departmentId: DEPARTMENT },
    });

    expect(router.currentRoute.value.name).toBe(MODULE_UNAVAILABLE_ROUTE);
    expect(router.currentRoute.value.params.moduleKey).toBe(
      MODULE_QUALIFICATIONS,
    );
  });

  it("takes the module's permission-denied surface with it", async () => {
    /*
     * `ims.restricted` explains which Incident Command role opens the
     * workspace. With Incident Management inactive there is no such role and no
     * workspace, so a reader sent there would be told to go and ask for
     * authority nobody in the organization can hold.
     */
    establish(MODULE_INCIDENT_MANAGEMENT);

    const router = guardedRouter();

    await router.push({ name: "ims.restricted" });

    expect(router.currentRoute.value.name).toBe(MODULE_UNAVAILABLE_ROUTE);
    expect(router.currentRoute.value.params.moduleKey).toBe(
      MODULE_INCIDENT_MANAGEMENT,
    );
  });

  it("leaves the core surfaces beside it reachable", async () => {
    establish(MODULE_SCHEDULING, MODULE_INCIDENT_MANAGEMENT, MODULE_DOCUMENTS);

    const router = guardedRouter();

    for (const name of [
      "staff.me",
      "events.departments.logistics",
      "events.departments.roster",
      "events.departments.credits.index",
      "events.departments.exports.index",
      "readiness",
    ]) {
      await router.push({
        name,
        params: {
          eventId: LOCAL_FIELD_FIXTURE.eventId,
          departmentId: DEPARTMENT,
        },
      });

      expect(router.currentRoute.value.name).toBe(name);
    }
  });
});

describe("an offline client", () => {
  /*
   * CLIENT-007 and technical spec 15A.8: the module set is cached with the
   * permission cache because it rides on the same document, so a device with no
   * node in reach gates on the set the server enforced when it last answered.
   * Nothing here is fetched — the document is installed as a cached one, which
   * is the seam a durable copy is restored through.
   */
  it("gates on the cached module set", async () => {
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldOrganizationsWithout(MODULE_DOCUMENTS),
      }),
      "cache",
    );
    selectSessionDepartment(DEPARTMENT);

    expect(routeModuleActive("staff.documents.index")).toBe(false);
    expect(routeModuleActive("staff.shifts.index")).toBe(true);

    const router = guardedRouter();

    await router.push("/staff/documents");

    expect(router.currentRoute.value.name).toBe(MODULE_UNAVAILABLE_ROUTE);
    expect(router.currentRoute.value.params.moduleKey).toBe(MODULE_DOCUMENTS);
  });

  it("stops gating when the cached session stops granting access", async () => {
    /*
     * Past the locked event's window a cached document grants nothing
     * (CLIENT-008), and a client with no access has no module answer either —
     * the same document that would have said which modules run is the one that
     * has stopped being usable. The sign-in guard is what such a client meets,
     * not a sentence about its organization's product.
     */
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldOrganizationsWithout(MODULE_DOCUMENTS),
        events: [
          {
            ...localFieldSessionDocument().events[0],
            active_event_window_ends_at: "2020-01-01T00:00:00+00:00",
          },
        ],
      }),
      "cache",
    );

    expect(routeModuleActive("staff.documents.index")).toBe(true);
    expect(moduleGateRedirect({ name: "staff.documents.index" })).toBeNull();
  });
});
