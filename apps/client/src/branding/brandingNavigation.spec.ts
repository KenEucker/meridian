import { afterEach, describe, expect, it } from "vitest";

import {
  FIXTURE_ORGANIZER_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  selectFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";
import { useNavigationSections } from "@/components/workflowLinks";

/**
 * The branding surfaces have to be reachable, not merely routable (M15A.6,
 * M15A.7; BRAND-019).
 *
 * A screen with no navigation entry is a screen nobody finds. These assert the
 * two entries exist and that each appears only for the role BRAND-019 gives
 * the authority to.
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
  afterEach(() => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
  });

  it("offers organization branding to an organizer", () => {
    selectFixtureDepartment(FIXTURE_ORGANIZER_DEPARTMENT_ID);

    expect(labelsIn("Organization pages")).toContain("Branding");
  });

  it("does not offer organization branding from a normal department", () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);

    expect(labelsIn("Organization pages")).not.toContain("Branding");
  });

  it("offers department branding to a department lead", () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);

    expect(labelsIn("Department pages")).toContain("Branding");
  });

  it("does not offer department branding to a department member with no admin authority", () => {
    selectFixtureDepartment(FIXTURE_GATE_DEPARTMENT_ID);

    expect(labelsIn("Department pages")).not.toContain("Branding");
  });

  it("routes department branding at the selected department", () => {
    selectFixtureDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);

    const link = useNavigationSections()
      .value.flatMap((section) => section.links)
      .find(
        (candidate) =>
          candidate.label === "Branding" &&
          candidate.to.name === "events.departments.branding",
      );

    expect(link?.to.params?.departmentId).toBe(FIXTURE_RANGERS_DEPARTMENT_ID);
  });
});
