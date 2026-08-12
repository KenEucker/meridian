import { afterEach, describe, expect, it } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createMemoryHistory, createRouter } from "vue-router";

import App from "@/App.vue";
import { registerNavigationGuards, routes } from "@/router";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  LOCAL_FIELD_DEPARTMENT_IDS,
  localFieldOrganizationsWithout,
  localFieldSessionDocument,
} from "@/session/localFieldSessionFixture";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";
import {
  MODULE_DOCUMENTS,
  MODULE_INCIDENT_MANAGEMENT,
  MODULE_QUALIFICATIONS,
  MODULE_SCHEDULING,
  type ModuleKey,
} from "@/session/sessionModules";

/*
 * Module absence is not permission denial, and a reader has to be able to tell
 * which one they are looking at (M19.16; MOD-013, MOD-015, MOD-022; DIR-005's
 * "its absence shall not be presented as permission denial", applied to the
 * module system).
 *
 * The two surfaces exist side by side in this file on purpose. `ims.restricted`
 * is the permission-denied page for the Incident Command workspace: it names
 * the roles that open it, because they exist and somebody holds them. The
 * module absence page names none, because with the module inactive there is no
 * role to name and nobody to ask — and a reader sent to the wrong one of these
 * two spends their afternoon chasing a grant that cannot be issued.
 */

async function render(path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes });

  // The application's own guards, so an address owned by an inactive module is
  // answered here the way it is answered in the running client rather than by a
  // second copy of the rule.
  registerNavigationGuards(router, "field");

  await router.push(path);
  await router.isReady();

  const wrapper = mount(App, { global: { plugins: [router] } });

  await flushPromises();

  return wrapper;
}

function establish(...inactive: readonly ModuleKey[]): void {
  installClientSession(
    localFieldSessionDocument({
      organizations: localFieldOrganizationsWithout(...inactive),
    }),
    "network",
  );
  selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
}

afterEach(() => {
  clearClientSession();
  resetSelectedSessionDepartment();
});

describe("the surface a member reaches when their organization does not run a module", () => {
  it("tells them what their organization runs, naming the module", async () => {
    establish(MODULE_SCHEDULING);

    const text = (await render("/module-unavailable/scheduling")).text();

    expect(text).toContain("Scheduling is not part of this organization");
    expect(text).toContain("Your organization does not use Scheduling");
    // MOD-022: the key is an identifier and never reaches the reader.
    expect(text).not.toContain("scheduling");
  });

  it("says nothing about the reader's authority", async () => {
    /*
     * The whole of the distinction. Every phrase below belongs to the other
     * page: each describes a reader whose standing falls short, and this
     * reader's standing is not what is missing. The word "permission" itself is
     * not forbidden — the copy uses it to rule the explanation out, which is the
     * opposite of claiming it.
     */
    establish(MODULE_DOCUMENTS);

    const text = (await render("/module-unavailable/documents")).text();

    for (const denial of [
      "access required",
      "permission to",
      "you do not have",
      "your role",
      "restricted",
      "not authorized",
    ]) {
      expect(text.toLowerCase()).not.toContain(denial);
    }

    expect(text).toContain("not a permission problem");
  });

  it("points at the surface that resolves it rather than at a person to ask", async () => {
    establish(MODULE_QUALIFICATIONS);

    const text = (await render("/module-unavailable/qualifications")).text();

    expect(text).toContain("An organizer can turn Qualifications on");
    expect(text).toContain("Organization configuration");
  });

  it("reads differently from the permission-denied page for the same workspace", async () => {
    /*
     * Both answer "you cannot see the Incidents workspace" and they answer it
     * for different reasons, so the sentences must not be interchangeable. The
     * denied page names IC roles; the absence page names the module and no role
     * at all.
     */
    establish();

    const denied = (await render("/ims/restricted")).text();

    expect(denied).toContain("Incident Command access required");
    expect(denied).toContain("IC Viewer");

    const absent = (await render("/module-unavailable/ims")).text();

    expect(absent).toContain("Incident Management is not part of this organization");
    expect(absent).not.toContain("IC Viewer");
    expect(absent).not.toContain("access required");
  });

  it("is where the workspace's own address lands once the module is off", async () => {
    // The two halves joined up: the guard sends the address here, and here is
    // where the sentence is. A reader who typed `/ims/restricted` from a
    // bookmark is told about their organization rather than about IC roles.
    establish(MODULE_INCIDENT_MANAGEMENT);

    const wrapper = await render("/ims/restricted");

    expect(wrapper.text()).toContain(
      "Incident Management is not part of this organization",
    );
    expect(wrapper.text()).not.toContain("IC Viewer");
  });
});
