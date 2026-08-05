// The reporting export entry points, and what runs them (M16.22; CLIENT-019,
// CLIENT-023; REPORT-001, REPORT-006, REPORT-007, REPORT-015; UI contract 12.6).
//
// An export is not a page of data this client renders. It is a file the node
// generates, audits, and names, and the only thing the client owns is the entry
// point: who may see it, what it says before it is run, and the two-step
// download that fetches it without ever putting a credential in a link.
//
// Three properties are load-bearing:
//
//  1. **The node decides the scope.** `ReportingExportAccess` resolves an
//     organizer to the whole event and a department role to its own departments
//     (REPORT-006, REPORT-007), and it resolves that again when the file is
//     served rather than trusting what was decided at issuance. Nothing here
//     re-derives it, so the surface describes the rule instead of predicting its
//     outcome. A client that got the prediction wrong would be printing a
//     promise the file then broke.
//  2. **The capability is checked where it was granted.** The export capability
//     arrives on a role, and the session document attaches roles to the
//     department they resolved at, so the check is on the grant. This is the
//     same shape `organizerAdminSession` uses and for the same reason.
//  3. **The credential never reaches the link.** The download goes through
//     M16.12: the token asks for a short-lived scoped URL and the browser
//     navigates to it (CLIENT-019, CLIENT-020; REPORT-015). Authorization is
//     decided when that URL is issued, which is why a refusal is something the
//     surface prints rather than a tab opening onto an error page.
//
// M18.25 completes the list. All five Alpha 1 exports (REPORT-001 through
// REPORT-005) now have a `download-url` sibling on the node and a descriptor
// here, so the export surfaces of M18.26 offer one download path rather than
// one export that works differently from its four siblings.
//
// M18.26 adds the last thing a surface needs and a descriptor cannot carry:
// which of REPORT-014's two surfaces a caller's standing belongs to. That is a
// question about the role rather than about the capability, because all five
// codes are granted to organizers and to department roles alike — see
// `ReportingExportReach`.

import { computed, type ComputedRef } from "vue";

import {
  downloadThroughShortLivedUrl,
  shortLivedDownloadEndpoints,
  type ShortLivedDownloadUrl,
} from "@/downloads/shortLivedDownload";
import {
  CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
  CAPABILITY_REPORTS_CREDITS_EARNED_EXPORT,
  CAPABILITY_REPORTS_HOURS_WORKED_EXPORT,
  CAPABILITY_REPORTS_SHIFT_ROSTER_EXPORT,
  CAPABILITY_REPORTS_STAFF_CONTACT_EXPORT,
  ROLE_LEAD_ORGANIZER,
  ROLE_ORGANIZER,
} from "@/session/permissionCodes";
import {
  selectedSessionDepartment,
  sessionEventContext,
} from "@/session/sessionAccess";
import type { SessionRole } from "@/session/sessionDocument";

/**
 * One export, described well enough that somebody can decide whether to run it
 * without opening the file (REPORT-014's requirement, stated here for the one
 * entry point Alpha 1 has).
 */
export interface ReportingExportDescriptor {
  readonly id: string;
  readonly label: string;
  /** The file format, named where the button is, so nobody is surprised. */
  readonly format: string;
  /** The capability code that permits running it. */
  readonly capability: string;
  /**
   * The endpoint that issues this export's short-lived URL, for the event.
   *
   * Carried on the descriptor rather than resolved by the caller so a surface
   * that offers a list of exports cannot run the wrong one: the entry that was
   * rendered is the entry that names its own endpoint.
   */
  readonly endpoint: (eventId: string) => string;
  /** What the rows are, one sentence. */
  readonly contains: string;
  /** The columns, in the order the node writes them. */
  readonly columns: readonly string[];
  /**
   * Fields deliberately absent from the file.
   *
   * Stated before generation so an organizer can see that emergency contacts
   * are excluded without having to open it (REPORT-010).
   */
  readonly excludes: readonly string[];
  /**
   * Columns the node appends only when a rule holds, and the rule.
   *
   * The staff contact export is the only one that has any: emergency contacts
   * ride along for a caller who leads every department in the file (REPORT-009)
   * and never for an organizer (REPORT-010). Which of those the caller is
   * depends on the scope the node resolves, and property 1 above says this
   * module does not predict that — so the rule is stated and the outcome is
   * not. A surface prints it as a condition, not as a promise.
   */
  readonly conditionalColumns?: {
    readonly columns: readonly string[];
    readonly condition: string;
  };
}

/**
 * Credential eligibility (REPORT-001; M13.1).
 *
 * Columns and exclusions mirror `CredentialEligibilityExportService`, which
 * excludes phone number, emergency contact, and date of birth by construction —
 * an age-related block is conveyed by its reason rather than by exporting a
 * birth date.
 */
export const CREDENTIAL_ELIGIBILITY_EXPORT: ReportingExportDescriptor = {
  id: "credential-eligibility",
  label: "Credential eligibility",
  format: "CSV",
  capability: CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
  endpoint: shortLivedDownloadEndpoints.credentialEligibilityExport,
  contains:
    "One row per staff member with an event credential, carrying the status the domain last recorded, the reason behind a block or revocation, the shifts that counted toward it, and the departments those shifts belong to.",
  columns: [
    "event_name",
    "staff_legal_name",
    "staff_preferred_name",
    "staff_handle",
    "staff_email",
    "departments",
    "credential_status",
    "status_reason",
    "status_reason_label",
    "credential_shift_count",
    "credential_updated_at",
    "revoked_at",
  ],
  excludes: ["Phone numbers", "Emergency contacts", "Dates of birth"],
};

/**
 * Shift roster (REPORT-002; M13.2).
 *
 * One row per staff member on a shift, plus one row for a shift nobody is on,
 * so an unfilled shift is visible in the file rather than missing from it.
 * Phone numbers and emergency contacts are excluded by construction, which is
 * REPORT-008 and applies to every caller including a department lead who would
 * be allowed them on the contact list.
 */
export const SHIFT_ROSTER_EXPORT: ReportingExportDescriptor = {
  id: "shift-roster",
  label: "Shift roster",
  format: "CSV",
  capability: CAPABILITY_REPORTS_SHIFT_ROSTER_EXPORT,
  endpoint: shortLivedDownloadEndpoints.shiftRosterExport,
  contains:
    "One row per staff member on a shift, and one row for a shift nobody is on yet, carrying the department and team, the scheduled window, capacity and roster size, and whether the staff member signed up or was assigned. A cancelled shift is reported as cancelled rather than dropped.",
  columns: [
    "event_name",
    "department",
    "team",
    "shift_title",
    "shift_status",
    "shift_starts_at",
    "shift_ends_at",
    "shift_capacity",
    "assigned_staff_count",
    "staff_legal_name",
    "staff_preferred_name",
    "staff_handle",
    "staff_email",
    "assignment_status",
    "assignment_source",
  ],
  excludes: ["Phone numbers", "Emergency contacts", "Dates of birth"],
};

/**
 * Staff contact list (REPORT-003; M13.3).
 *
 * The one Alpha 1 export permitted to carry contact details. Phone numbers are
 * on every copy — a contact list without them would not be one — and emergency
 * contacts are appended only for a caller who leads every department in the
 * file, which is why they are conditional columns here rather than columns.
 */
export const STAFF_CONTACT_EXPORT: ReportingExportDescriptor = {
  id: "staff-contact",
  label: "Staff contact list",
  format: "CSV",
  capability: CAPABILITY_REPORTS_STAFF_CONTACT_EXPORT,
  endpoint: shortLivedDownloadEndpoints.staffContactExport,
  contains:
    "One row per active department membership in the departments working this event, carrying the department, the teams held in it, contact details, and the membership and organization statuses, so somebody who is Inactive or Ineligible is reported rather than quietly missing.",
  columns: [
    "event_name",
    "department",
    "department_code",
    "teams",
    "staff_legal_name",
    "staff_preferred_name",
    "staff_handle",
    "staff_email",
    "staff_phone",
    "department_membership_status",
    "organization_status",
  ],
  excludes: ["Dates of birth"],
  conditionalColumns: {
    columns: ["emergency_contact_name", "emergency_contact_phone"],
    condition:
      "Included only when every exported row belongs to a department you hold a department role in (REPORT-009). An organizer's file omits the columns entirely, including when narrowed to one department, because narrowing changes which rows are exported and not the authority you came by (REPORT-010).",
  },
};

/**
 * Actual hours worked (REPORT-004; M13.4).
 *
 * Rows come from recorded hours alone, so a no-show, an open check-in, and an
 * unworked shift produce none. The scheduled window sits beside the actual one
 * rather than being absorbed into it, so a short or long shift reads as a
 * difference (HOURS-001).
 */
export const HOURS_WORKED_EXPORT: ReportingExportDescriptor = {
  id: "hours-worked",
  label: "Hours worked",
  format: "CSV",
  capability: CAPABILITY_REPORTS_HOURS_WORKED_EXPORT,
  endpoint: shortLivedDownloadEndpoints.hoursWorkedExport,
  contains:
    "One row per recorded hours record, carrying the scheduled window and scheduled minutes beside the actual window, the minutes and decimal hours worked, the hours status, and the correction state with its correction and freeze moments.",
  columns: [
    "event_name",
    "department",
    "team",
    "shift_title",
    "shift_starts_at",
    "shift_ends_at",
    "scheduled_minutes",
    "staff_legal_name",
    "staff_preferred_name",
    "staff_handle",
    "staff_email",
    "actual_started_at",
    "actual_ended_at",
    "minutes_worked",
    "hours_worked",
    "hours_status",
    "correction_state",
    "corrected_at",
    "frozen_at",
  ],
  excludes: ["Phone numbers", "Emergency contacts", "Dates of birth"],
};

/**
 * Credits earned (REPORT-005; M13.6).
 *
 * Rows come from the credit ledger, so frozen hours no calculation run has
 * reached yet are absent — what was worked rather than earned is the hours
 * worked export's answer. Every basis column is read from the entry's frozen
 * calculation basis, so a policy renamed or re-rated afterwards does not
 * restate what the event already paid (CREDIT-005).
 */
export const CREDITS_EARNED_EXPORT: ReportingExportDescriptor = {
  id: "credits-earned",
  label: "Credits earned",
  format: "CSV",
  capability: CAPABILITY_REPORTS_CREDITS_EARNED_EXPORT,
  endpoint: shortLivedDownloadEndpoints.creditsEarnedExport,
  contains:
    "One row per credit ledger entry, carrying the credited hours, the policy name and multiplier they were priced at, and the resulting credits in adjacent columns, plus the policy source, the calculation moment, and the hours record's freeze and correction moments, so the arithmetic is re-checkable inside the row.",
  columns: [
    "event_name",
    "department",
    "team",
    "shift_title",
    "shift_starts_at",
    "shift_ends_at",
    "staff_legal_name",
    "staff_preferred_name",
    "staff_handle",
    "staff_email",
    "entry_type",
    "credit_status",
    "minutes_worked",
    "hours",
    "credit_policy_name",
    "credit_multiplier",
    "credits",
    "policy_source",
    "calculated_at",
    "hours_frozen_at",
    "hours_corrected_at",
  ],
  excludes: ["Phone numbers", "Emergency contacts", "Dates of birth"],
};

/**
 * The exports this client has an entry point for, in the order they are
 * offered: who is credentialed, who is scheduled, how to reach them, what they
 * worked, and what it earned.
 */
export const REPORTING_EXPORTS: readonly ReportingExportDescriptor[] = [
  CREDENTIAL_ELIGIBILITY_EXPORT,
  SHIFT_ROSTER_EXPORT,
  STAFF_CONTACT_EXPORT,
  HOURS_WORKED_EXPORT,
  CREDITS_EARNED_EXPORT,
];

/**
 * How wide the standing behind an export reaches (REPORT-006, REPORT-007).
 *
 * Not a prediction of what the file will hold — property 1 above still stands,
 * and the node resolves the rows twice regardless of what was asked here. It is
 * the question REPORT-014 asks of the *caller*: an organizer belongs on the
 * organization/event-scoped surface and a department role belongs on the
 * department-scoped one, and the two say different things about their scope
 * because they are different scopes.
 *
 * `"any"` is for a surface whose subject already fixes the scope —
 * `organizer.credentials` is a page about one event's credentials for whoever
 * may administer them — and which therefore states the rule for both cases.
 */
export type ReportingExportReach = "any" | "event" | "department";

/**
 * The role codes whose export authority covers the whole event (REPORT-006).
 *
 * The client's copy of `ReportingExportAccess::ORGANIZATION_WIDE_ROLES`. It is
 * a copy and worth naming as one: if the node ever widens that list, a role it
 * added would reach the department surface here rather than the organizer one.
 * That is a surface offered too narrowly, never a file served too widely — the
 * node resolves the scope when it issues the URL and again when it serves the
 * file, and neither reads anything this module decided.
 */
const ORGANIZATION_WIDE_ROLE_CODES: readonly string[] = [
  ROLE_ORGANIZER,
  ROLE_LEAD_ORGANIZER,
];

function roleReaches(role: SessionRole, reach: ReportingExportReach): boolean {
  if (reach === "any") {
    return true;
  }

  const organizationWide = ORGANIZATION_WIDE_ROLE_CODES.includes(
    role.role_code,
  );

  return reach === "event" ? organizationWide : !organizationWide;
}

/** The event an export would run against, and the standing that permits it. */
export interface ReportingExportAuthority {
  readonly eventId: string;
  readonly eventLabel: string;
  /** The roles carrying the capability here, named as the node named them. */
  readonly roleLabel: string;
  /** The exports those roles permit, in the order they are offered. */
  readonly exports: readonly ReportingExportDescriptor[];
  /**
   * The department those roles are held in.
   *
   * The narrowing a department-scoped surface sends with every request, and the
   * department an organizer-scoped one is *not* narrowed to — an organizer's
   * standing is held in the Organizers Department and reaches every other one.
   */
  readonly departmentId: string;
  readonly departmentLabel: string;
}

/**
 * What this client may export from a named list at a named reach, or null when
 * it may export nothing on it.
 *
 * Which exports a surface offers is the surface's question, not this module's:
 * the export surfaces of M18.26 offer all five, and `organizer.credentials`
 * offers the one that reads the records it is a page for. Passing the list in
 * keeps the role label honest too — a page naming the standing that reached it
 * should name the role granting the export it is showing, not one granting some
 * other export the same person also holds.
 *
 * The reach narrows which roles are read for that answer, so a department lead
 * is not offered a surface promising the whole event and an organizer is not
 * offered one promising their own department. See {@link ReportingExportReach}.
 *
 * Null covers four separate cases and a surface treats them as one: no session,
 * no resolved event, no role here carrying one of these capabilities, or none
 * carrying it at this reach. All of them mean there is no export to offer, and
 * CLIENT-005 says an unavailable action is absent rather than disabled.
 *
 * The event is required rather than optional because every export endpoint is
 * event-scoped: with no event id there is no URL to ask for.
 */
export function reportingExportAuthorityFor(
  offered: readonly ReportingExportDescriptor[],
  reach: ReportingExportReach = "any",
): ComputedRef<ReportingExportAuthority | null> {
  return computed<ReportingExportAuthority | null>(() => {
    const department = selectedSessionDepartment.value;
    const event = sessionEventContext.value;

    if (department === null || event === null) {
      return null;
    }

    /*
     * Read from the roles rather than from the department's flattened
     * capability list, because the reach is a property of the role that carried
     * the code and flattening throws it away. At `"any"` the two are the same
     * set, which is what keeps the surfaces that do not ask about reach reading
     * exactly what they read before.
     */
    const atReach = department.roles.filter((role) =>
      roleReaches(role, reach),
    );

    const permitted = offered.filter((descriptor) =>
      atReach.some((role) => role.capabilities.includes(descriptor.capability)),
    );

    if (permitted.length === 0) {
      return null;
    }

    const granting = atReach.filter((role) =>
      permitted.some((descriptor) =>
        role.capabilities.includes(descriptor.capability),
      ),
    );

    const roleNames = [
      ...new Set(
        granting
          .map((role) => role.role_name)
          .filter(
            (name): name is string => typeof name === "string" && name !== "",
          ),
      ),
    ];

    return {
      eventId: event.eventId,
      eventLabel: event.eventLabel ?? "This event",
      roleLabel: roleNames.length > 0 ? roleNames.join(", ") : "Your role",
      exports: permitted,
      departmentId: department.departmentId,
      departmentLabel: department.departmentLabel,
    };
  });
}

/**
 * REPORT-014's two surfaces, each reading the standing it is written for
 * (M18.26).
 *
 * Module-level rather than built per view, because they are the answer to a
 * question about the session rather than about a page, and navigation asks the
 * same question the surfaces do.
 *
 * Both read the department the client is currently working in, so somebody who
 * organizes this organization and leads a department in it is offered the
 * organizer surface while working in the Organizers Department and the
 * department one while working in their own — each in the place their standing
 * for it is held, which is where the node checks for it too.
 */
export const organizerReportingExportAuthority = reportingExportAuthorityFor(
  REPORTING_EXPORTS,
  "event",
);

export const departmentReportingExportAuthority = reportingExportAuthorityFor(
  REPORTING_EXPORTS,
  "department",
);

/**
 * Run one export for one event (REPORT-015).
 *
 * `departmentId` narrows a scope the caller already holds; it can never widen
 * one, and the node refuses a department outside the caller's scope rather than
 * quietly ignoring it. Omitted, the file covers everything that caller's own
 * authority reaches.
 *
 * Throws `MeridianApiError` when the node refuses, which is the whole reason the
 * URL is asked for first: an unauthorized export is a sentence to print, not a
 * broken tab.
 */
export async function downloadReportingExport(
  descriptor: ReportingExportDescriptor,
  eventId: string,
  departmentId: string | null = null,
): Promise<ShortLivedDownloadUrl> {
  return downloadThroughShortLivedUrl(
    descriptor.endpoint(eventId),
    departmentId === null ? {} : { department_id: departmentId },
  );
}

/** Credential eligibility, for callers that mean that one export (REPORT-001). */
export async function downloadCredentialEligibilityExport(
  eventId: string,
  departmentId: string | null = null,
): Promise<ShortLivedDownloadUrl> {
  return downloadReportingExport(
    CREDENTIAL_ELIGIBILITY_EXPORT,
    eventId,
    departmentId,
  );
}
