// The permission catalog codes this client reasons about (M16.6; CLIENT-003,
// CLIENT-004; technical spec 15.1, 15.2).
//
// These are string constants, not a permission model. The catalog itself lives
// on the server in `App\Domain\Permissions\PermissionCatalog`, which is what the
// server enforces from and what `GET /api/me` publishes; this file only spells
// the codes the client compares against so a typo becomes a compile error rather
// than a navigation entry that never appears.
//
// Only codes some surface actually consults are listed. A code the client never
// checks does not belong here: an unused constant looks like a client-side
// permission the reader has to go looking for, and there is none — every
// decision made from these is presentation, and the server refuses the request
// regardless of what the client rendered (CLIENT-006, UI contract 19A.1).

/** Capability codes, as published by the permission catalog. */
export const CAPABILITY_DEPARTMENT_PRESENCE_MANAGE = "department.presence.manage";
export const CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE =
  "department.attendance.manage";
export const CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE =
  "department.equipment.manage";
export const CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN =
  "department.deployments.assign";
export const CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE =
  "department.schedule.manage";
export const CAPABILITY_DEPARTMENT_ADMINISTER = "department.administer";
export const CAPABILITY_DEPARTMENT_BRANDING_MANAGE =
  "department.branding.manage";
export const CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE =
  "organization.departments.manage";
export const CAPABILITY_ORGANIZATION_STAFF_MANAGE = "organization.staff.manage";
export const CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE =
  "organization.incident_types.manage";
/**
 * Maintaining organization-level team designations (TEAM-016) — today, which
 * team within the configured Organizers Department carries Staff Coordinator
 * authority. Department team designations answer to `department.administer`
 * on the department administration surface instead, so no code exists for
 * them here.
 */
export const CAPABILITY_ORGANIZATION_DESIGNATIONS_MANAGE =
  "organization.designations.manage";
/**
 * Editing organization configuration (ORG-018, ORG-020): the lifecycle
 * inactivity thresholds, the hours correction grace period, the calendar year
 * start, the default credit policy, and the Organizers, default Incident
 * Command, and default Placement department designations. Organizers and Lead
 * Organizers only, and a separate code from incident types and designations
 * because each featureset on the configuration surface carries its own
 * authority.
 */
export const CAPABILITY_ORGANIZATION_CONFIGURATION_MANAGE =
  "organization.configuration.manage";
/**
 * Maintaining the organization's credit policies and starting credit
 * calculation runs (ORG-009, ORG-020; CREDIT-001; M18.16). Organizers and
 * Lead Organizers only: ORG-010 rules out a department default policy so a
 * department cannot reprice its own work, and this capability is where that
 * rule lives on the client.
 */
export const CAPABILITY_ORGANIZATION_CREDIT_POLICIES_MANAGE =
  "organization.credit_policies.manage";
export const CAPABILITY_ORGANIZATION_BRANDING_MANAGE =
  "organization.branding.manage";
export const CAPABILITY_POLICIES_VIEW_PUBLISHED = "policies.view_published";
export const CAPABILITY_INCIDENTS_VIEW = "incidents.view";
export const CAPABILITY_INCIDENTS_CREATE = "incidents.create";
export const CAPABILITY_INCIDENTS_UPDATE = "incidents.update";
export const CAPABILITY_INCIDENTS_ADD_NOTE = "incidents.add_note";
export const CAPABILITY_INCIDENTS_LINK_FIELD_REPORT =
  "incidents.link_field_report";
export const CAPABILITY_INCIDENTS_PRINT = "incidents.print";
export const CAPABILITY_FIELD_REPORTS_VIEW_EVENT = "field_reports.view_event";
export const CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT =
  "reports.credential_eligibility.export";
/**
 * Revoking an event credential (CRED-011).
 *
 * Next to the export above and deliberately not the same thing. The export is
 * held by department leads for their own department; this is held by organizers
 * and Incident Command leads for the whole event, and the credentials surface
 * offers each half to whoever holds it.
 */
export const CAPABILITY_EVENT_CREDENTIALS_REVOKE = "event.credentials.revoke";
/**
 * Maintaining and reviewing document acknowledgment requirements (POL-023,
 * POL-046, POL-047).
 *
 * Gates the organizer review surface and nothing a staff member does. Reading
 * and answering your own acknowledgments needs no capability at all — being
 * asked is a fact about you, not a permission — so `staff.document-acknowledgments`
 * and the signup surface check for requirements rather than for a code.
 */
export const CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW =
  "documents.acknowledgments.review";
/**
 * Deciding staff handle and profile picture change requests (VOL-019; M18.20A,
 * M18.20D).
 *
 * Organizers, Lead Organizers, and the Staff Coordinator hold it and no other
 * role does. It gates the reviewer's queue and nothing a staff member does with
 * their own record: submitting, withdrawing, and clearing your own request need
 * no capability at all, because they are acts on yourself.
 */
export const CAPABILITY_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW =
  "staff.profile-change-requests.review";

/**
 * Role codes, for the two surfaces that answer to standing rather than to a
 * capability.
 *
 * Department Overview belongs to the department lead and Team Overview belongs
 * to a designated team lead. Both roles now carry
 * `department.attendance.manage` (M18.13; TEAM-015) — that is what opens the
 * Logistics Desk attendance work to them — but their overview surfaces are
 * theirs because of who they are, not because of a capability, so the role
 * codes stay the gate here. Both codes still come from the session response —
 * they are what CLIENT-002 calls the effective role codes — so nothing here
 * reintroduces fixture-driven authority.
 */
export const ROLE_DEPARTMENT_LEAD = "department_lead";
export const ROLE_SHIFT_LEAD = "shift_lead";
