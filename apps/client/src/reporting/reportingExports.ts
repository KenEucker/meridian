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
// Only credential eligibility is here. The other four Alpha 1 exports
// (REPORT-002 through REPORT-005) have endpoints but no `download-url` sibling
// and no surface to run them from; both arrive with the reporting surfaces of
// M18.25 and M18.26, which extend the list below rather than replacing it.

import { computed } from "vue";

import {
  downloadThroughShortLivedUrl,
  shortLivedDownloadEndpoints,
  type ShortLivedDownloadUrl,
} from "@/downloads/shortLivedDownload";
import { CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT } from "@/session/permissionCodes";
import {
  selectedSessionDepartment,
  sessionEventContext,
} from "@/session/sessionAccess";

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

/** The exports this client has an entry point for. */
export const REPORTING_EXPORTS: readonly ReportingExportDescriptor[] = [
  CREDENTIAL_ELIGIBILITY_EXPORT,
];

/** The event an export would run against, and the standing that permits it. */
export interface ReportingExportAuthority {
  readonly eventId: string;
  readonly eventLabel: string;
  /** The roles carrying the capability here, named as the node named them. */
  readonly roleLabel: string;
  /** The exports those roles permit, in the order they are offered. */
  readonly exports: readonly ReportingExportDescriptor[];
}

/**
 * What this client may export, or null when it may export nothing.
 *
 * Null covers three separate cases and the surface treats them as one: no
 * session, no resolved event, or no role here carrying an export capability. All
 * three mean there is no export to offer, and CLIENT-005 says an unavailable
 * action is absent rather than disabled.
 *
 * The event is required rather than optional because every export endpoint is
 * event-scoped: with no event id there is no URL to ask for.
 */
export const reportingExportAuthority = computed<ReportingExportAuthority | null>(
  () => {
    const department = selectedSessionDepartment.value;
    const event = sessionEventContext.value;

    if (department === null || event === null) {
      return null;
    }

    const permitted = REPORTING_EXPORTS.filter((descriptor) =>
      department.capabilities.includes(descriptor.capability),
    );

    if (permitted.length === 0) {
      return null;
    }

    const granting = department.roles.filter((role) =>
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
    };
  },
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
