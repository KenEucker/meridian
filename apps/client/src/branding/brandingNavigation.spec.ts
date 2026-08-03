import { afterEach, beforeEach, describe, expect, it } from "vitest";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
} from "@/session/localFieldSessionFixture";

import { useNavigationSections } from "@/components/workflowLinks";
import { clearClientSession } from "@/session/clientSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";

/**
 * The branding surfaces have to be reachable, not merely routable (M15A.6,
 * M15A.7; BRAND-019).
 *
 * A screen with no navigation entry is a screen nobody finds. These assert the
 * two entries exist and that each appears only for the capability BRAND-019
 * gives the authority to.
 */

function linkLabels(): { section: string; labels: string[] }[] {
  return useNavigationSections().value.map((section) => ({
    section: section.title,
    labels: section.links.map((link) => link.label),
  }));
}

function labelsIn(section: string): string[] {
  return linkLabels().find((entry) => entry.section === section)?.labels ?? [];
}

describe("branding navigation", () => {
  beforeEach(() => {
    installLocalFieldSession();
  });

  afterEach(() => {
    clearClientSession();
    resetSelectedSessionDepartment();
  });

  it("offers organization branding to an organizer", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.organizer);

    expect(labelsIn("Organization pages")).toContain("Branding");
  });

  it("does not offer organization branding from a normal department", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    expect(labelsIn("Organization pages")).not.toContain("Branding");
  });

  it("offers department branding to a department lead", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    expect(labelsIn("Department pages")).toContain("Branding");
  });

  it("does not offer department branding to a department member with no admin authority", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.gate);

    expect(labelsIn("Department pages")).not.toContain("Branding");
  });

  it("does not offer department branding to a team lead", () => {
    // BRAND-019 is narrower than department admin: `department.branding.manage`
    // is what permits the surface, and a designated team lead holds none.
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.dpw);

    expect(labelsIn("Department pages")).not.toContain("Branding");
  });

  it("routes department branding at the selected department", () => {
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    const link = useNavigationSections()
      .value.flatMap((section) => section.links)
      .find(
        (candidate) =>
          candidate.label === "Branding" &&
          candidate.to.name === "events.departments.branding",
      );

    expect(link?.to.params?.departmentId).toBe(LOCAL_FIELD_DEPARTMENT_IDS.rangers);
  });
});
