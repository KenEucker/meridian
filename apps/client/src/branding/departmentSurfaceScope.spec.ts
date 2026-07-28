import { describe, expect, it } from "vitest";

import {
  departmentSurfaceAttributes,
  isDepartmentScopedSurface,
} from "@/branding/departmentSurfaceScope";

describe("department surface scoping", () => {
  it("scopes department operations surfaces", () => {
    for (const route of [
      "events.departments.overview",
      "events.departments.logistics",
      "events.departments.operations",
      "events.departments.planning",
      "events.departments.teams.index",
      "events.departments.shifts.edit",
      "events.departments.equipment.index",
      "events.departments.trainings.show",
    ]) {
      expect(isDepartmentScopedSurface(route), route).toBe(true);
    }
  });

  it("leaves incident and IMS surfaces alone", () => {
    // BRAND-012: an incident belongs to Command, not to the reporting
    // department, and tinting it would imply ownership it does not have.
    for (const route of [
      "ims.incidents.index",
      "ims.incidents.show",
      "ims.incidents.edit",
      "ims.field-reports.index",
      "ims.field-reports.show",
      "ims.restricted",
    ]) {
      expect(isDepartmentScopedSurface(route), route).toBe(false);
    }
  });

  it("leaves The Briefing alone", () => {
    for (const route of ["briefing.hub", "briefing.add-note"]) {
      expect(isDepartmentScopedSurface(route), route).toBe(false);
    }
  });

  it("leaves organization-level and cross-department surfaces alone", () => {
    for (const route of [
      "organizer.departments.index",
      "organizer.departments.edit",
      "organizer.staff.index",
      "organizer.documents.index",
      "home",
      "events.info",
      "staff.me",
      "staff.field-reports.index",
      "settings.about",
      "readiness",
      "not-found",
    ]) {
      expect(isDepartmentScopedSurface(route), route).toBe(false);
    }
  });

  it("treats an unknown or missing route as unscoped", () => {
    // A screen added later renders on the organization surface until someone
    // deliberately adds it to the allow list.
    expect(isDepartmentScopedSurface(null)).toBe(false);
    expect(isDepartmentScopedSurface("")).toBe(false);
    expect(isDepartmentScopedSurface("some.future.screen")).toBe(false);
  });

  it("emits the branding attribute everywhere and the surface attribute only in scope", () => {
    // The accent travels with the department; the background does not.
    expect(
      departmentSurfaceAttributes("events.departments.overview", "dept-1"),
    ).toEqual({
      "data-department-branding": "dept-1",
      "data-department-surface": "dept-1",
    });

    expect(departmentSurfaceAttributes("ims.incidents.index", "dept-1")).toEqual(
      { "data-department-branding": "dept-1" },
    );

    expect(departmentSurfaceAttributes("briefing.hub", "dept-1")).toEqual({
      "data-department-branding": "dept-1",
    });
  });

  it("emits nothing without a department", () => {
    expect(
      departmentSurfaceAttributes("events.departments.overview", null),
    ).toEqual({});
  });

  it("keeps the exclusion list winning over the allow list", () => {
    // Nothing currently matches both, and this is what keeps a future
    // department-scoped incident child from inheriting the background.
    expect(isDepartmentScopedSurface("events.departments.ims.something")).toBe(
      true,
    );
    expect(isDepartmentScopedSurface("ims.events.departments.something")).toBe(
      false,
    );
  });
});
