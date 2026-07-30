import {
  departmentHasCapability,
  selectedSessionDepartment,
} from "@/session/sessionAccess";
import {
  CAPABILITY_DEPARTMENT_BRANDING_MANAGE,
  CAPABILITY_ORGANIZATION_BRANDING_MANAGE,
} from "@/session/permissionCodes";

/**
 * Route props for the two branding administration surfaces (M15A.6, M15A.7).
 *
 * Resolving here rather than inside the views means the views take plain props
 * and can be mounted in tests without a router or a session — which is what lets
 * the permission-denied and overrides-disabled states be tested directly.
 *
 * `canManage` comes from the capability codes the session response carries
 * (M16.6; CLIENT-004). It is what the views render their denied state from, and
 * BRAND-019 draws the split the two capabilities already encode: organizers own
 * the organization profile, department leads and department administration own
 * their own department's. A team lead holds neither and is refused by the view
 * and by the server alike.
 */

/**
 * The organization the client is operating in.
 *
 * Still the seeded local organization: resolving the branding organization from
 * the session context is part of the organization and event switcher (M16.7).
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
    canManage: departmentHasCapability(
      selectedSessionDepartment.value,
      CAPABILITY_ORGANIZATION_BRANDING_MANAGE,
    ),
  };
}

export function departmentBrandingRouteProps(): DepartmentBrandingRouteProps {
  const department = selectedSessionDepartment.value;

  return {
    organizationId: FIXTURE_ORGANIZATION_ID,
    departmentId: department?.departmentId ?? "",
    departmentName: department?.departmentLabel ?? "",
    canManage: departmentHasCapability(
      department,
      CAPABILITY_DEPARTMENT_BRANDING_MANAGE,
    ),
  };
}
