/**
 * Which surfaces may take a department background (M15A.10; BRAND-012).
 *
 * The rule is written as an allow list keyed on route-name prefix, not as a
 * deny list. A deny list means every screen added after this one silently
 * inherits the department background and someone has to notice; an allow list
 * means a new screen renders on the organization surface until somebody says
 * otherwise, which is the safe default for a rule whose failure mode is
 * "incident management is now painted in Rangers green".
 *
 * The three exclusions BRAND-012 names each fail the allow list for a
 * different reason, and it is worth being explicit about them:
 *
 *   - **IMS / incident surfaces** (`ims.*`) are cross-department by
 *     construction. An incident is Command's, not the reporting department's,
 *     and tinting it with a department's identity would suggest ownership the
 *     incident does not have.
 *   - **The Briefing** (`briefing.*`) is event-wide. Its whole purpose is that
 *     everyone reads the same thing.
 *   - **Organization-level surfaces** (`organizer.*`) are about the
 *     organization, and several of them list every department at once; there is
 *     no single department whose background would be correct.
 *
 * `staff.*` is deliberately outside the allow list too. A staff member's own
 * pages follow them across departments, and the department they happen to have
 * selected is not what those pages are about.
 */

/** Route-name prefixes whose surfaces are department-scoped. */
const DEPARTMENT_SCOPED_PREFIXES = ["events.departments."] as const;

/**
 * Route-name prefixes that must never take a department background even if a
 * prefix above would otherwise match. Checked first.
 *
 * This exists for the case a department-scoped route tree later grows an
 * incident-adjacent child. Nothing currently matches both lists, and the
 * scoping test asserts that.
 */
const EXCLUDED_PREFIXES = ["ims.", "briefing.", "organizer."] as const;

export function isDepartmentScopedSurface(routeName: string | null): boolean {
  if (!routeName) {
    return false;
  }

  if (EXCLUDED_PREFIXES.some((prefix) => routeName.startsWith(prefix))) {
    return false;
  }

  return DEPARTMENT_SCOPED_PREFIXES.some((prefix) =>
    routeName.startsWith(prefix),
  );
}

/**
 * The attributes a surface element should carry.
 *
 * `data-department-branding` is what the generated branding stylesheet keys
 * on, and `data-department-surface` is what the client stylesheet paints. They
 * are separate because the accent applies wherever the department appears
 * while the background applies only here (UI implementation contract 10.3).
 */
export function departmentSurfaceAttributes(
  routeName: string | null,
  departmentId: string | null,
): Record<string, string> {
  if (!departmentId) {
    return {};
  }

  const attributes: Record<string, string> = {
    "data-department-branding": departmentId,
  };

  if (isDepartmentScopedSurface(routeName)) {
    attributes["data-department-surface"] = departmentId;
  }

  return attributes;
}
