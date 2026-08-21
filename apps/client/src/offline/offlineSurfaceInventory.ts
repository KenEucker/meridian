// The offline surface audit, as a list rather than as a hope (M18.53;
// CLIENT-001, UI-020; technical spec 9.3; UI implementation contract 11.13,
// 16.2).
//
// Every routed surface in the shared client is here, and each is held to one of
// two outcomes with no third:
//
//   - **`renders-offline`** — with no node in reach the surface still answers,
//     from the offline read set (M18.46 through M18.50), from the cached session
//     document, or from a store this device owns outright. Where the copy is
//     stored rather than live, the surface discloses it through the freshness
//     contract its own screen already uses (`ReadFreshness`, `StaleReadNotice`);
//     `readFreshness.ts` owns that disclosure and nothing here restates it.
//   - **`connection-required`** — the answer only exists where the node is, and
//     the surface says so. That is not a defect. An export is a file the node
//     generates; a dashboard, the Event Horizon, and the Planning Table are
//     compiled when they are asked for and are not records to be held; an audit
//     trail is history this device was never sent. What *would* be a defect is a
//     spinner that never resolves, a panel that renders blank, or a transport
//     failure printed as though the surface were broken — and the three
//     properties in `offlineSurfaceInventory.spec.ts` are what keep those out.
//
// **Why a list and not a rule.** Which surfaces work offline is a product fact,
// not a consequence of how a read model happens to be written. Left implicit it
// is whatever the read set most recently covered, discovered by a lead standing
// in a field. Written down, a surface that stops rendering offline fails a test
// that names it, and a surface that was never expected to is on the record as
// such with the reason beside it.
//
// **Write surfaces are here too.** The audit is about read-only surfaces, and
// a create or edit form is not one — but every form in this client has a read
// behind it (the record it edits, the options it offers), and the honesty
// question about that read is exactly the same question. Leaving them out would
// have meant a second, unwritten list.
//
// **What is not here.** Orchid and God Mode, which are server-rendered and are
// not the shared client; Insights, which is compiled on read in the same console
// and has no client surface. Both are named in the plan as legitimately
// connected-only, and neither is something this file could assert about.

/** The two outcomes. There is deliberately no third. */
export type OfflineSurfaceOutcome = "renders-offline" | "connection-required";

export interface OfflineSurfaceEntry {
  /**
   * The vue-router route name, which is also the UI implementation contract's
   * screen id. One key for the router, the contract, and this record.
   */
  readonly route: string;
  readonly outcome: OfflineSurfaceOutcome;
  /**
   * For a rendering surface, what it renders from. For a connection-required
   * one, why the answer cannot be held on this device.
   */
  readonly basis: string;
  /**
   * Something the surface renders with no node in reach.
   *
   * This is what makes the inventory testable rather than decorative: the audit
   * spec mounts every surface against a node that does not answer and looks for
   * this. A surface that quietly stopped rendering offline, or that started
   * rendering a blank panel where a sentence used to be, fails on its own row.
   */
  readonly offlineText: string;
}

/**
 * The audit.
 *
 * In router order, so a route added without a decision about its offline
 * behavior is visible as a gap in the sequence as well as a failure in the spec.
 */
export const OFFLINE_SURFACE_INVENTORY: readonly OfflineSurfaceEntry[] =
  Object.freeze([
    /* ---- The deployment root, the public pages, and the way in ---- */
    {
      route: "home",
      outcome: "renders-offline",
      basis:
        "the cached session document: the home directory is the pages this session may reach, which the session response already answered",
      offlineText: "Your profile, event information, and personal pages.",
    },
    {
      route: "public.marketing",
      outcome: "connection-required",
      basis:
        "the marketing page and its interest form are served by the node, and whether a node serves them at all is PUBLIC-006's answer rather than something a device may assume",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizations.index",
      outcome: "renders-offline",
      basis:
        "the cached session document's organizations, which is the same list the switcher would offer",
      offlineText: "The organizations you hold an association with",
    },
    {
      route: "organizations.events.index",
      outcome: "renders-offline",
      basis: "the cached session document's events for the chosen organization",
      offlineText: "Events you hold an association with",
    },
    {
      route: "events.departments.index",
      outcome: "renders-offline",
      basis:
        "the cached session document's departments — deliberately not connected-only (M18.29), because a lead choosing where to work is most likely to be standing where there is no signal",
      offlineText: "The departments you are associated with in this event",
    },
    {
      route: "public.participate",
      outcome: "connection-required",
      basis:
        "an applicant holds no session and no read set, so there is nothing on the device for the form to be composed from",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "public.apply",
      outcome: "connection-required",
      basis: "the same public form, with the event already chosen",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "login",
      outcome: "renders-offline",
      basis:
        "the form renders and the node is asked only when a code is requested, so a device out of coverage sees why rather than a blank screen",
      offlineText: "Meridian will send you a login code",
    },
    {
      route: "auth.code.entry",
      outcome: "renders-offline",
      basis: "the entry form, with the node asked only when a code is submitted",
      offlineText: "Enter your login code",
    },
    {
      route: "readiness",
      outcome: "renders-offline",
      basis:
        "the device's own checks — readiness is a statement about this device, so a node that cannot be reached is one of its answers rather than an obstacle to producing them",
      offlineText: "Device readiness",
    },
    {
      route: "settings.about",
      outcome: "renders-offline",
      basis: "this device's own diagnostics, display preferences, and session",
      offlineText: "Device readiness",
    },

    /* ---- Department surfaces ---- */
    {
      route: "events.departments.show",
      outcome: "connection-required",
      basis:
        "a dashboard is compiled by the node when it is asked for and is not a record this device holds (M18.28)",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "events.departments.overview",
      outcome: "connection-required",
      basis:
        "the selected shift's situational picture is composed across presence, attendance, and equipment at read time",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.info",
      outcome: "renders-offline",
      basis:
        "the read set's event_info section: the node's own assembly and render of the published guidance (POL-022), carried whole at refresh so fragments arrive resolved rather than re-rendered on the device",
      offlineText: "the copy this device stored",
    },
    {
      route: "events.departments.logistics",
      outcome: "renders-offline",
      basis:
        "the 9.3 Logistics indexes, composed into the desk on the device: presence, the shifts in front of it, and each person's attendance, with the card verdicts derived by the node's own rule from the same four facts it reads. Since M18.54 the addition and the on-site mark are offline writes too, so the desk offers both against the node's own offering rule and queues them; eligibility is still the node's and refuses on replay. Correcting hours and handing equipment over stay refused — the correction grace period is the node's clock, and availability is not confirmable from a stored copy",
      offlineText: "This node could not be reached",
    },
    {
      route: "events.departments.operations",
      outcome: "renders-offline",
      basis:
        "the 9.3 Operations cache list: stored deployment options and current assignments, with 'active now' re-derived from stored shift windows by the node's own rule. Reassigning a deployment stays a connected write and refuses where it stands",
      offlineText: "This node could not be reached",
    },
    {
      route: "events.departments.planning",
      outcome: "renders-offline",
      basis:
        "the 9.3 planning aggregates — identity-free plan-versus-actual rows the node's own PlanVersusActual computed at composition (SLB-019) — with team and date narrowing applied as filtering, never as recomputed arithmetic",
      offlineText: "This node could not be reached",
    },
    {
      route: "events.departments.teams.index",
      outcome: "connection-required",
      basis:
        "team administration reads the department's teams and their memberships as an administrative list, which the read set does not carry",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.documents.index",
      outcome: "renders-offline",
      basis:
        "the read set's published policy and procedure sections, searched in memory and disclosed as narrowed",
      offlineText: "This node could not be reached",
    },
    {
      route: "events.departments.documents.create",
      outcome: "connection-required",
      basis:
        "the authoring workspace needs the scopes this caller maintains, which a stored copy of the library cannot establish, and publishing is a connected write",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "events.departments.documents.edit",
      outcome: "connection-required",
      basis: "the same workspace, over one document's source",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "events.departments.trainings.index",
      outcome: "connection-required",
      basis:
        "training schedules and completion state are not in the 9.3 read set",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.trainings.create",
      outcome: "connection-required",
      basis: "the training form reads the department's existing trainings",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.trainings.edit",
      outcome: "connection-required",
      basis: "one training's setup, roster, and completions",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.trainings.show",
      outcome: "connection-required",
      basis: "one training's information page",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.shifts.index",
      outcome: "connection-required",
      basis:
        "department shift administration reads coverage and assignment counts, which is a different question from the staff board's own shifts",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.shifts.create",
      outcome: "connection-required",
      basis:
        "the shift form reads the department's teams, deployments, and requirement options",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.shifts.edit",
      outcome: "connection-required",
      basis: "one shift's own record and its options",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.equipment.index",
      outcome: "connection-required",
      basis:
        "the equipment inventory reports what is out right now, which is a live fact rather than a stored one",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.roster",
      outcome: "connection-required",
      basis:
        "the roster carries emergency contacts for the leads VOL-012 names, and which fields a caller may read is decided by the node on the read rather than held on the device",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "events.departments.deployments.index",
      outcome: "connection-required",
      basis:
        "deployments report who is standing at each of them now, and archiving one depends on that count",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "events.departments.credits.index",
      outcome: "connection-required",
      basis:
        "the credit ledger is frozen accounting the node holds, and the hours carrying no entry are counted against it at read time",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "events.departments.exports.index",
      outcome: "connection-required",
      basis:
        "an export is a file the node generates; it is not a command and the outbox has nothing to replay (technical spec 19.2)",
      offlineText: "Exports are generated by the node and require a server connection",
    },
    {
      route: "events.departments.branding",
      outcome: "renders-offline",
      basis:
        "the branding profile this device stored, which is what paints the department's identity before any network answers",
      offlineText: "Department logo",
    },
    {
      route: "events.departments.teams.create",
      outcome: "renders-offline",
      basis:
        "an empty form needs nothing read; the create itself is a connected write and is refused at issue time",
      offlineText: "Create team",
    },
    {
      route: "events.departments.teams.edit",
      outcome: "connection-required",
      basis: "one team's record and membership",
      offlineText: "Check the connection to this node",
    },
    {
      route: "events.departments.teams.show",
      outcome: "connection-required",
      basis: "the team's overview, composed from its members and their standing",
      offlineText: "Check the connection to this node",
    },

    /* ---- Staff surfaces ---- */
    {
      route: "staff.dashboard",
      outcome: "connection-required",
      basis:
        "a dashboard is compiled by the node when it is asked for and is not a record this device holds (M18.28)",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "staff.me",
      outcome: "renders-offline",
      basis:
        "the cached session document: who this is, where they are working, and what they hold",
      offlineText: "Staff profile",
    },
    {
      route: "staff.event-horizon",
      outcome: "renders-offline",
      basis:
        "the acknowledgment kind compiled on the device from the read set (HORIZON-016), with waivers, trainings, shift signup, and team coverage named as kinds it could not evaluate — the read set holds nothing for the first three, and carries only the shifts this member already holds for the fourth, so a compiled signup list could never show a shift with a place left",
      offlineText: "could not be checked at all",
    },
    {
      route: "directory",
      outcome: "renders-offline",
      basis:
        "the stored Directory projection (M18.77; DIR-037): the sections the node composed from the same visibility rule the online chart read serves, so the device holds what its user could have retrieved and nothing else; the offline search index is that stored set, and profile pictures stay behind under the M8.2 replication boundary, rendering as lettermarks",
      offlineText: "the copy this device stored",
    },
    {
      route: "staff.profile.edit",
      outcome: "connection-required",
      basis:
        "the editable profile and which of its fields need review are the node's answer (VOL-019)",
      offlineText: "Check the connection to this node",
    },
    {
      route: "staff.profile.requests",
      outcome: "connection-required",
      basis: "where a submitted request stands is decided where it was submitted",
      offlineText: "Check the connection to this node",
    },
    {
      route: "staff.shifts.index",
      outcome: "renders-offline",
      basis:
        "the read set's shifts and this staff member's own assignments; signing up and withdrawing stay connected-only and the board says so",
      offlineText: "This node could not be reached",
    },
    {
      route: "signup.documents.acknowledge",
      outcome: "renders-offline",
      basis:
        "the read set's acknowledgment requirements and answers; the document text is the node's render and the surface says it is missing rather than showing an empty document",
      offlineText: "This node could not be reached",
    },
    {
      route: "staff.documents.acknowledgments",
      outcome: "renders-offline",
      basis: "the same stored requirements and answers, across every context",
      offlineText: "This node could not be reached",
    },
    {
      route: "staff.documents.index",
      outcome: "renders-offline",
      basis:
        "the read set's published documents, searched in memory and disclosed as narrowed",
      offlineText: "This node could not be reached",
    },
    {
      route: "staff.documents.show",
      outcome: "connection-required",
      basis:
        "a document is served as the node's render with its fragments resolved (POL-022); the read set carries the source, not the rendered text",
      offlineText: "Check the connection to this node",
    },
    {
      route: "staff.workstation-code",
      outcome: "renders-offline",
      basis:
        "the scan control and the two code forms (M18.61); the node is asked only when a grant or a code is requested, and a request that cannot reach it says so in the panel it was made from. The history added in M18.71 is a read and does need the node, so offline it reports a failed read in its own panel rather than an empty history — one panel unable to answer, on a surface whose other three still work",
      offlineText: "Workstations",
    },
    {
      route: "staff.field-reports.index",
      outcome: "renders-offline",
      basis:
        "the Field Reports this device authored, from the device's own durable store — the surface has never had a node read (M9.4), so it states that its list is this device's rather than presenting it as every report the author filed",
      offlineText: "Field Reports submitted from another device",
    },
    {
      route: "staff.field-reports.create",
      outcome: "renders-offline",
      basis:
        "a Field Report is an Alpha 1 offline write: the form composes on the device and the submission queues in the outbox (technical spec 9.4)",
      offlineText: "Field Reports are finalized on submit",
    },
    {
      route: "staff.field-reports.show",
      outcome: "renders-offline",
      basis:
        "the same device-owned store, including a report still waiting in the outbox; appending is an offline write too",
      offlineText: "Field Report",
    },

    /* ---- Incident Command ---- */
    {
      route: "ims.dashboard",
      outcome: "connection-required",
      basis:
        "a dashboard is compiled by the node when it is asked for and is not a record this device holds (M18.28)",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "ims.incidents.index",
      outcome: "renders-offline",
      basis:
        "the bounded IC preload (technical spec 9.3 as amended 2026-08-20): the event's open incidents and its most recent entries, in the list endpoint's own shape. Search, filters, presets, and paging stay the node's machinery; the stored answer states it is the whole device copy",
      offlineText: "This node could not be reached",
    },
    {
      route: "ims.field-reports.index",
      outcome: "renders-offline",
      basis:
        "the read set's Field Reports for the event, unioned with anything this device has queued and not yet sent",
      offlineText: "This node could not be reached",
    },
    {
      route: "ims.field-reports.create",
      outcome: "renders-offline",
      basis:
        "the dictated Field Report composes and queues on the device; only the staff directory it can be taken on behalf of needs the node, and the picker says so",
      offlineText: "It is finalized on submit",
    },
    {
      route: "ims.field-reports.show",
      outcome: "renders-offline",
      basis:
        "the read set's Field Reports for the event, the same section the list beside it renders from; the incident linkage is reported as what the stored copy knows rather than as none",
      offlineText: "Stored report",
    },
    {
      route: "ims.incidents.create",
      outcome: "connection-required",
      basis:
        "incident creation and editing are online-only in Alpha 1 (UI contract 16.2), and the form keeps what was typed rather than queueing it",
      offlineText: "requires server connection",
    },
    {
      route: "ims.incidents.edit",
      outcome: "connection-required",
      basis:
        "the same rule over one incident's record — though since the 2026-08-20 IC preload the form fills from the stored copy, so what needs the connection is the edit itself, and the surface says so while showing the record",
      offlineText: "requires server connection",
    },
    {
      route: "ims.incidents.show",
      outcome: "renders-offline",
      basis:
        "the stored IC preload and the viewed-incident cache (INC-017), whichever holds the newer copy of this incident; editing stays connected-only and every command refuses where it stands",
      offlineText: "Stored incident",
    },
    {
      route: "ims.restricted",
      outcome: "renders-offline",
      basis:
        "the cached session document is what says the reader lacks Incident Command standing, so the explanation needs no node",
      offlineText: "Incident Command access required",
    },

    /* ---- Organizer surfaces ---- */
    {
      route: "organizer.dashboard",
      outcome: "connection-required",
      basis:
        "a dashboard is compiled by the node when it is asked for and is not a record this device holds (M18.28)",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizer.staff.index",
      outcome: "connection-required",
      basis:
        "the organization's staff administration reads status, credentials, and lifecycle state that never travel to a device",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.departments.index",
      outcome: "connection-required",
      basis:
        "the administrative department list carries archived rows and status the session document does not",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.configuration.index",
      outcome: "connection-required",
      basis:
        "governance values are edited against the node's copy and are frozen during the active event window, and a form filled from a stale copy would offer to save a value nobody read",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.departments.create",
      outcome: "renders-offline",
      basis:
        "an empty form needs nothing read; the create itself is a connected write",
      offlineText: "Create department",
    },
    {
      route: "organizer.departments.edit",
      outcome: "connection-required",
      basis: "one department's administrative record",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.credentials.index",
      outcome: "connection-required",
      basis:
        "who holds a credential now is the node's answer, and revoking one removes shifts other people are being scheduled around",
      offlineText: "needs a connection to the node",
    },
    {
      route: "organizer.exports.index",
      outcome: "connection-required",
      basis:
        "an export is a file the node generates; it is not a command and the outbox has nothing to replay (technical spec 19.2)",
      offlineText: "Exports are generated by the node and require a server connection",
    },
    {
      route: "organizer.document-acknowledgments.index",
      outcome: "connection-required",
      basis:
        "who has acknowledged what, across the organization, is a review of everybody's records rather than this reader's own",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.applications.index",
      outcome: "connection-required",
      basis:
        "applications are submitted by people who are not yet staff, so nothing about them is in a staff member's read set",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizer.applications.show",
      outcome: "connection-required",
      basis: "one application, read for a decision",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizer.events.index",
      outcome: "connection-required",
      basis:
        "event administration carries the published dates and the active event window as separate fields, and the window is what moves authority between nodes",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizer.audit.index",
      outcome: "connection-required",
      basis:
        "an audit trail is the organization's history and is never sent to a device",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizer.profile-change-requests.index",
      outcome: "connection-required",
      basis: "a queue of other people's pending requests",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.waivers.index",
      outcome: "connection-required",
      basis:
        "waiver administration reads and records completions for other people",
      offlineText: "Check the connection to this node",
    },
    {
      route: "organizer.branding",
      outcome: "renders-offline",
      basis:
        "the branding profile this device stored, which is what paints the organization's identity before any network answers",
      offlineText: "Display name",
    },
    {
      route: "organizer.documents.index",
      outcome: "renders-offline",
      basis:
        "the read set's published documents for the organization, searched in memory and disclosed as narrowed",
      offlineText: "This node could not be reached",
    },
    {
      route: "organizer.documents.create",
      outcome: "connection-required",
      basis:
        "the authoring workspace needs the scopes this caller maintains, which a stored copy of the library cannot establish",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "organizer.documents.edit",
      outcome: "connection-required",
      basis: "the same workspace, over one document's source",
      offlineText: "cannot reach a Meridian node",
    },

    /* ---- Kiosk ---- */
    {
      route: "kiosk.home",
      outcome: "connection-required",
      basis:
        "the workstation's home screen carries the dashboard the node compiles for the kiosk group; the pinned context itself is remembered from the node's last answer (M18.32) so the machine still knows where it is",
      offlineText: "cannot reach a Meridian node",
    },
    {
      route: "kiosk.setup",
      outcome: "renders-offline",
      basis:
        "the workstation identifier and the last pinned context this machine was told, which is what an unreachable node leaves it with",
      offlineText: "Kiosk setup",
    },
    {
      route: "kiosk.workstation-login",
      outcome: "renders-offline",
      basis:
        "the code entry form; the node is asked only when a code is submitted. The scan-to-sign-in QR (M18.60) needs the node to open a request, so with no node in reach the surface states that scan is unavailable and the typed field stands — the fallback the M18.53 rule requires, never a spinner or a blank square",
      offlineText: "Login code",
    },
    {
      route: "kiosk.switch-user",
      outcome: "renders-offline",
      basis:
        "the session being ended is this workstation's own, and what is still waiting to be sent is the outbox's answer",
      offlineText: "Switch user",
    },
    {
      route: "kiosk.reauth",
      outcome: "renders-offline",
      basis:
        "the confirmation form; the node checks the code when it is submitted",
      offlineText: "Confirm it is still you",
    },
    {
      route: "kiosk.shift-board",
      outcome: "connection-required",
      basis:
        "the desk's attendance board reads who is on shift at this workstation's scope right now; its writes queue in the outbox",
      offlineText: "Check the connection to the node",
    },
    {
      route: "kiosk.safe-timeout",
      outcome: "renders-offline",
      basis:
        "it holds no name, event, or record — which is what makes it safe, and what makes it need nothing",
      offlineText: "Session timed out",
    },
    {
      route: "module.unavailable",
      outcome: "renders-offline",
      basis:
        "the module is in the address and the sentence is built from it, so a reader who reloads this page in a field is told the same thing the node told them (M19.16; MOD-013)",
      offlineText: "not part of this organization",
    },
    {
      route: "not-found",
      outcome: "renders-offline",
      basis: "an address that matched nothing needs no node to say so",
      offlineText: "Page not found",
    },
  ]);

/** One surface's recorded outcome, or null for a route nobody classified. */
export function offlineSurfaceEntry(route: string): OfflineSurfaceEntry | null {
  return (
    OFFLINE_SURFACE_INVENTORY.find((entry) => entry.route === route) ?? null
  );
}
