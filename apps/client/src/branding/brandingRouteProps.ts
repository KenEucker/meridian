import {
  fixtureDepartmentHasBrandingAccess,
  fixtureDepartmentHasOrganizerDepartmentAccess,
  selectedFixtureDepartment,
} from "@/department-teams/fixtureDepartmentAccess";

/**
 * Route props for the two branding administration surfaces (M15A.6, M15A.7).
 *
 * Organization and department identity still come from the development fixture
 * session the rest of the client uses until auth and organization selection
 * land. Keeping that resolution here rather than inside the views means the
 * views take plain props and can be mounted in tests without a router or a
 * fixture — which is what lets the permission-denied and overrides-disabled
 * states be tested directly.
 */

/**
 * The organization the fixture session is operating in.
 *
 * Shared with the server fixture seeded by
 * `php artisan meridian:seed-local-field-fixture`.
 */
export const FIXTURE_ORGANIZATION_ID = "88888888-8888-4888-8888-888888888888";

export interface OrganizationBrandingRouteProps {
  readonly organizationId: string;
  readonly canManage: boolean;
}

export interface DepartmentBrandingRouteProps {
  readonly organizationId: string;
  readonly departmentId: string;
  readonly departmentName: string;
  readonly canManage: boolean;
}

export function organizationBrandingRouteProps(): OrganizationBrandingRouteProps {
  return {
    organizationId: FIXTURE_ORGANIZATION_ID,
    // BRAND-019: organization branding is organizer-only. The fixture models
    // organizer reach as organizer-department access.
    canManage: fixtureDepartmentHasOrganizerDepartmentAccess(
      selectedFixtureDepartment.value,
    ),
  };
}

export function departmentBrandingRouteProps(): DepartmentBrandingRouteProps {
  const department = selectedFixtureDepartment.value;

  return {
    organizationId: FIXTURE_ORGANIZATION_ID,
    departmentId: department.departmentId,
    departmentName: department.departmentLabel,
    canManage: fixtureDepartmentHasBrandingAccess(department),
  };
}
