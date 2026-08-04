// The public participation surface, as the client reads it (M18.21A; APP-016,
// APP-017, APP-018).
//
// Everything here runs without a session, which makes this the only model in
// the client that must work with no token, no cached permission set, and no
// organization context. It therefore takes its organization from the URL rather
// than from `sessionAccess` — the visitor is not signed in and has no context to
// select from — and it reads through the same `meridianJson` helper as
// everything else, which simply sends no Authorization header when the client
// holds no credential.
//
// Nothing is cached. A page that lists the events an organization is recruiting
// for is a claim about right now, and a stale copy invites somebody to apply to
// an event that closed last week.

import { meridianJson } from "@/api/meridianApi";

export interface ParticipationBranding {
  readonly displayName: string;
  readonly lettermark: string;
  /** Null means Meridian's own palette — the organization chose none. */
  readonly palette: Readonly<Record<string, string>> | null;
  readonly fullLockupUrl: string | null;
  readonly compactMarkUrl: string | null;
}

export interface ParticipationEvent {
  readonly slug: string;
  readonly name: string;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly timezone: string | null;
}

export interface OrganizationParticipation {
  readonly organizationSlug: string;
  readonly organizationName: string;
  /** APP-018: whether this organization takes applications naming no event. */
  readonly acceptsOrganizationApplications: boolean;
  readonly branding: ParticipationBranding;
  readonly events: readonly ParticipationEvent[];
}

export interface DepartmentInterestOption {
  readonly id: string;
  readonly name: string;
}

export interface EventParticipation {
  readonly organizationSlug: string;
  readonly organizationName: string;
  readonly branding: ParticipationBranding;
  readonly event: ParticipationEvent & {
    readonly acceptingApplications: boolean;
  };
  /** APP-011: optional, non-binding, and empty when the event has none. */
  readonly departmentInterests: readonly DepartmentInterestOption[];
}

interface BrandingPayload {
  readonly display_name?: string;
  readonly lettermark?: string;
  readonly palette?: Record<string, string> | null;
  readonly full_lockup_url?: string | null;
  readonly compact_mark_url?: string | null;
}

interface EventPayload {
  readonly slug?: string;
  readonly name?: string;
  readonly starts_at?: string | null;
  readonly ends_at?: string | null;
  readonly timezone?: string | null;
  readonly accepting_applications?: boolean;
}

function toBranding(
  payload: BrandingPayload | undefined,
): ParticipationBranding {
  return {
    displayName: payload?.display_name ?? "Meridian",
    lettermark: payload?.lettermark ?? "M",
    palette: payload?.palette ?? null,
    fullLockupUrl: payload?.full_lockup_url ?? null,
    compactMarkUrl: payload?.compact_mark_url ?? null,
  };
}

function toEvent(payload: EventPayload): ParticipationEvent {
  return {
    slug: payload.slug ?? "",
    name: payload.name ?? "Untitled event",
    startsAt: payload.starts_at ?? null,
    endsAt: payload.ends_at ?? null,
    timezone: payload.timezone ?? null,
  };
}

export async function getOrganizationParticipation(
  organizationSlug: string,
): Promise<OrganizationParticipation> {
  const payload = await meridianJson<{
    organization?: {
      slug?: string;
      name?: string;
      accepts_organization_applications?: boolean;
    };
    branding?: BrandingPayload;
    events?: readonly EventPayload[];
  }>(`/api/public/organizations/${encodeURIComponent(organizationSlug)}`);

  return {
    organizationSlug: payload?.organization?.slug ?? organizationSlug,
    organizationName: payload?.organization?.name ?? "This organization",
    acceptsOrganizationApplications:
      payload?.organization?.accepts_organization_applications === true,
    branding: toBranding(payload?.branding),
    events: (payload?.events ?? []).map(toEvent),
  };
}

export async function getEventParticipation(
  organizationSlug: string,
  eventSlug: string,
): Promise<EventParticipation> {
  const payload = await meridianJson<{
    organization?: { slug?: string; name?: string };
    branding?: BrandingPayload;
    event?: EventPayload;
    department_interests?: readonly DepartmentInterestOption[];
  }>(
    `/api/public/organizations/${encodeURIComponent(organizationSlug)}/events/${encodeURIComponent(eventSlug)}`,
  );

  const event = toEvent(payload?.event ?? {});

  return {
    organizationSlug: payload?.organization?.slug ?? organizationSlug,
    organizationName: payload?.organization?.name ?? "This organization",
    branding: toBranding(payload?.branding),
    event: {
      ...event,
      acceptingApplications: payload?.event?.accepting_applications !== false,
    },
    departmentInterests: payload?.department_interests ?? [],
  };
}

export interface ApplicationSubmission {
  readonly applicantLegalName: string;
  readonly applicantEmail: string;
  /** Omitted or empty applies to the organization itself (APP-001). */
  readonly eventSlug?: string | null;
  readonly departmentInterestIds?: readonly string[];
}

export interface SubmittedApplication {
  readonly applicationId: string;
  readonly scope: "organization" | "event";
}

/**
 * Submit an application (APP-001).
 *
 * Not routed through the command outbox. The outbox is the offline write path
 * for a client that holds a session and a device identity, and an applicant has
 * neither — a queued application on a stranger's browser would be a promise
 * Meridian cannot keep, since nothing would ever sign in to flush it. So this is
 * a connected write that fails visibly.
 */
export async function submitApplication(
  organizationSlug: string,
  submission: ApplicationSubmission,
): Promise<SubmittedApplication> {
  const payload = await meridianJson<{
    application_id?: string;
    scope?: string;
  }>(
    `/api/public/organizations/${encodeURIComponent(organizationSlug)}/applications`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        applicant_legal_name: submission.applicantLegalName,
        applicant_email: submission.applicantEmail,
        event_slug: submission.eventSlug ?? null,
        department_interest_ids: submission.departmentInterestIds ?? [],
      }),
    },
  );

  return {
    applicationId: payload?.application_id ?? "",
    scope: payload?.scope === "organization" ? "organization" : "event",
  };
}

/**
 * The shareable address for a participation surface (APP-017).
 *
 * Built from the browser's own origin so a link copied from a node is a link
 * back to that node, and readable rather than tokenised: the page is public,
 * following it grants nothing, and a token would make it look otherwise.
 */
export function participationLink(
  organizationSlug: string,
  eventSlug?: string | null,
): string {
  const origin =
    typeof window === "undefined"
      ? ""
      : window.location.origin.replace(/\/$/, "");
  const base = `${origin}/apply/${encodeURIComponent(organizationSlug)}`;

  return eventSlug == null || eventSlug === ""
    ? base
    : `${base}/${encodeURIComponent(eventSlug)}`;
}
