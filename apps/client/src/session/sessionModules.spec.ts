import { afterEach, describe, expect, it } from "vitest";

import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_ORGANIZATION_ID,
  localFieldOrganizationsWithout,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import {
  activeModulesIn,
  moduleAbsenceCopy,
  moduleActive,
  moduleActiveIn,
  moduleName,
  MODULE_DOCUMENTS,
  MODULE_EQUIPMENT,
  MODULE_KEYS,
  MODULE_NAMES,
  MODULE_SCHEDULING,
  sessionActiveModules,
} from "@/session/sessionModules";

/*
 * The modules an organization runs, as the client reads them (M19.16; MOD-015,
 * MOD-022; technical spec 11A.3, 15A.8).
 *
 * Every case establishes a session by installing a document of the shape
 * `GET /api/me` returns and reads the answer back out of the production path
 * (CLIENT-024). Nothing here reaches a node, and nothing here computes module
 * state: which modules an organization runs is the node's answer, and this
 * module only reads it.
 */

const DEPARTMENT = LOCAL_FIELD_DEPARTMENT_IDS.rangers;

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("the module catalogue this client spells", () => {
  it("names every module in Meridian's own terms", () => {
    // MOD-022: a key is an identifier and never a label.
    for (const module of MODULE_KEYS) {
      expect(MODULE_NAMES[module]).not.toBe(module);
      expect(MODULE_NAMES[module].length).toBeGreaterThan(0);
    }

    expect(moduleName(MODULE_SCHEDULING)).toBe("Scheduling");
    expect(moduleName("ims")).toBe("Incident Management");
    expect(moduleName("briefing")).toBe("The Briefing");
  });

  it("holds the eight modules MOD-002 lists and no others", () => {
    expect([...MODULE_KEYS]).toEqual([
      "scheduling",
      "ims",
      "documents",
      "qualifications",
      "equipment",
      "geography",
      "briefing",
      "insights",
    ]);
  });

  it("falls back to the key for a module a later build named", () => {
    // A node ahead of this client refusing a module it has never heard of. The
    // sentence is worse and it is still a sentence, which beats rendering
    // nothing at all.
    expect(moduleName("provisions")).toBe("provisions");
  });
});

describe("reading an organization's active set", () => {
  it("reports what the node said the organization runs", () => {
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldOrganizationsWithout(MODULE_SCHEDULING),
      }),
      "network",
    );

    expect(activeModulesIn(LOCAL_FIELD_ORGANIZATION_ID)).not.toContain(
      MODULE_SCHEDULING,
    );
    expect(activeModulesIn(LOCAL_FIELD_ORGANIZATION_ID)).toContain(
      MODULE_DOCUMENTS,
    );
  });

  it("reports nothing at all rather than an empty set when it cannot say", () => {
    /*
     * Three ways not to know, and all three have to be distinguishable from
     * "this organization runs nothing": no session, an organization the
     * document does not carry, and a document from a build older than MOD-015.
     * An empty list here would hide every module-owned surface in the product.
     */
    expect(activeModulesIn(LOCAL_FIELD_ORGANIZATION_ID)).toBeNull();

    installClientSession(localFieldSessionDocument(), "network");

    expect(activeModulesIn("org-nobody-told-this-client-about")).toBeNull();
    expect(activeModulesIn(null)).toBeNull();

    clearClientSession();
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldSessionDocument().organizations.map(
          ({ modules: _modules, ...organization }) => organization,
        ),
      }),
      "network",
    );

    expect(activeModulesIn(LOCAL_FIELD_ORGANIZATION_ID)).toBeNull();
  });

  it("presumes a module active while the set is unknown", () => {
    // The safe direction. A page that stands and is refused by the node costs a
    // reader one click; a page that vanishes because a field was missing costs
    // an organization a product it pays for.
    expect(moduleActiveIn(null, MODULE_SCHEDULING)).toBe(true);
    expect(moduleActiveIn("org-nobody-told-this-client-about", MODULE_DOCUMENTS)).toBe(
      true,
    );
  });

  it("ignores a module key this build does not know", () => {
    // A node ahead of this client. The keys this client can act on are the ones
    // it spells, and an unknown one is not one of them.
    installClientSession(
      localFieldSessionDocument({
        organizations: [
          {
            id: LOCAL_FIELD_ORGANIZATION_ID,
            name: "Northwood Collective",
            slug: "northwood-collective",
            status: "approved",
            archived_at: null,
            modules: ["scheduling", "provisions"],
          },
        ],
      }),
      "network",
    );

    expect(activeModulesIn(LOCAL_FIELD_ORGANIZATION_ID)).toEqual([
      MODULE_SCHEDULING,
    ]);
  });

  it("reads a malformed set as unknown rather than throwing the session away", () => {
    // Signing a device out to tidy a menu is the wrong trade. The document
    // still carries this user's permissions, and the node still enforces.
    installClientSession(
      localFieldSessionDocument({
        organizations: [
          {
            id: LOCAL_FIELD_ORGANIZATION_ID,
            name: "Northwood Collective",
            slug: "northwood-collective",
            status: "approved",
            archived_at: null,
            modules: "scheduling" as unknown as readonly string[],
          },
        ],
      }),
      "network",
    );

    expect(activeModulesIn(LOCAL_FIELD_ORGANIZATION_ID)).toBeNull();
    expect(moduleActive(MODULE_SCHEDULING)).toBe(true);
  });
});

describe("which organization is asked", () => {
  it("follows the department the client is working in", () => {
    /*
     * Authority in Meridian is scoped and so is module state. Somebody working
     * across two organizations is owed each one's answer where it applies —
     * asking a single session-wide set would let one organization's decision
     * take a surface away in the other.
     */
    const document = localFieldSessionDocument();
    const other = "org-cascadia-collective";

    installClientSession(
      localFieldSessionDocument({
        organizations: [
          ...localFieldOrganizationsWithout(MODULE_EQUIPMENT),
          {
            id: other,
            name: "Cascadia Collective",
            slug: "cascadia-collective",
            status: "approved",
            archived_at: null,
            modules: [...MODULE_KEYS],
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
    expect(moduleActive(MODULE_EQUIPMENT)).toBe(false);
    expect(sessionActiveModules.value).not.toContain(MODULE_EQUIPMENT);

    selectSessionDepartment("dept-cascadia");
    expect(moduleActive(MODULE_EQUIPMENT)).toBe(true);
  });

  it("says nothing while access is refused", () => {
    /*
     * CLIENT-008: past its event window a cached document grants nothing, and
     * a client with no access has no module answer either. Reporting the set
     * anyway would let an expired session keep deciding what the product is.
     */
    installClientSession(
      localFieldSessionDocument({
        organizations: localFieldOrganizationsWithout(MODULE_SCHEDULING),
        events: [
          {
            ...localFieldSessionDocument().events[0],
            active_event_window_ends_at: "2020-01-01T00:00:00+00:00",
          },
        ],
      }),
      "cache",
    );

    expect(sessionActiveModules.value).toBeNull();
    expect(moduleActive(MODULE_SCHEDULING)).toBe(true);
  });
});

describe("what a member is told when a module is absent", () => {
  it("says what the organization runs, not what the reader may do", () => {
    /*
     * The distinction MOD-013 exists for, in words. A permission denial is
     * about the reader and points at somebody who could grant them more; this
     * is about the organization, and there is nobody to ask. The copy must not
     * borrow the vocabulary of the other — "access", "permission", "your role"
     * would each send a member looking for a grant that cannot exist.
     */
    const copy = moduleAbsenceCopy(MODULE_SCHEDULING);

    expect(copy.heading).toContain("Scheduling");
    expect(copy.message).toContain("Your organization does not use Scheduling");
    expect(copy.message).toContain("not a permission problem");
    expect(copy.resolution).toContain("Organization configuration");

    for (const text of [copy.heading, copy.message]) {
      expect(text).not.toMatch(/permission to|access denied|your role|restricted/i);
    }
  });

  it("names the module in Meridian's own terms and never by key", () => {
    // MOD-022, at the one place a reader is most likely to see a key leak.
    const copy = moduleAbsenceCopy("ims");

    expect(copy.moduleName).toBe("Incident Management");
    expect(copy.heading).not.toContain("ims");
    expect(copy.message).not.toContain("ims");
  });
});
