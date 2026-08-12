// The landing page's feature tour, as data (M20.1, M20.3; PUBLIC-007,
// PUBLIC-008).
//
// One entry per major feature area of the platform, in the order a prospective
// organization meets the work: recruiting people, scheduling them, running the
// day, handling what goes wrong, and the records that hold it together. The
// catalogue is fixed in code the way the module catalogue is — a landing page
// is a claim about what the product does, and a data-defined one could claim
// something the build does not implement.
//
// Every screenshot is a static asset captured from the seeded Northwood
// development scenario and committed to the repository (PUBLIC-008). Nothing
// here reads live data, and no real organization's records can appear because
// no real organization is in the pipeline that produces the images —
// `scripts/marketing/capture-northwood-screenshots.mjs` drives a seeded
// development database and nothing else. The capture process is documented in
// `docs/process/marketing-screenshot-recapture.md`.
//
// The feature areas whose surfaces have not been built yet — the Briefing,
// Insights, and event geography — are deliberately absent rather than
// illustrated with mockups: a screenshot on this page is a promise that the
// surface exists.

export interface MarketingTourSection {
  /** Stable id, used for the section anchor and the screenshot filename. */
  readonly id: string;
  readonly title: string;
  /** What the feature does for an organization, in a couple of sentences. */
  readonly description: string;
  /** Public asset path of the committed Northwood screenshot. */
  readonly screenshot: string;
  /** What the screenshot shows, for a reader who cannot see it. */
  readonly screenshotAlt: string;
}

function screenshotPath(id: string): string {
  return `/assets/marketing/northwood/${id}.webp`;
}

export const MARKETING_FEATURE_TOUR: readonly MarketingTourSection[] = [
  {
    id: "applications",
    title: "Intake and applications",
    description:
      "Recruiting starts with a shareable application link, and applying " +
      "needs no account. Applications land in one review queue where " +
      "organizers approve, defer, or decline, and every decision records who " +
      "made it and when.",
    screenshot: screenshotPath("applications"),
    screenshotAlt:
      "The application review queue for an upcoming event, listing " +
      "applicants with their status — submitted, approved, rejected, and " +
      "deferred — and the controls to decide each one.",
  },
  {
    id: "scheduling",
    title: "Shifts and signup",
    description:
      "Departments plan shifts with capacity, eligible teams, and " +
      "requirements. Staff sign themselves up from their own shift board, " +
      "and a signup that would not work — a missing training, a lapsed " +
      "waiver, a full shift — is refused with the reason stated rather than " +
      "silently.",
    screenshot: screenshotPath("scheduling"),
    screenshotAlt:
      "A staff member's shift board showing open shifts across the event " +
      "with capacity, times, and signup controls, including one signup " +
      "refused with its reason named.",
  },
  {
    id: "operations",
    title: "Check-in, hours, and credits",
    description:
      "The Logistics Desk runs the day: check people in and out, add " +
      "somebody to a shift that is already running, and hand equipment " +
      "across the counter. Hours are recorded as they happen, and credits " +
      "are calculated from the policies the organization set.",
    screenshot: screenshotPath("operations"),
    screenshotAlt:
      "The Logistics Desk with its search front and center, showing a " +
      "department's staff, shifts, and equipment ready for check-in and " +
      "check-out.",
  },
  {
    id: "incidents",
    title: "Incident management",
    description:
      "Field reports and incidents live in one command-scoped record: " +
      "timestamped notes with authorship, linked incidents, attached " +
      "reports, and a history nothing falls out of. Access follows Incident " +
      "Command standing, not seniority.",
    screenshot: screenshotPath("incidents"),
    screenshotAlt:
      "The incident list for a running event, showing incidents across " +
      "status and priority with their types and responders.",
  },
  {
    id: "documents",
    title: "Policies and documents",
    description:
      "Policies, procedures, and event information are authored once, " +
      "published in versions, and readable anywhere — including offline. " +
      "Acknowledgment requirements record who accepted which version, and " +
      "waivers are documents too.",
    screenshot: screenshotPath("documents"),
    screenshotAlt:
      "The staff document library listing an organization's published " +
      "policies and procedures with their scopes.",
  },
  {
    id: "qualifications",
    title: "Trainings and waivers",
    description:
      "Trainings carry prerequisites, expirations, and scheduled sessions " +
      "with signup; completions are recorded one at a time or imported in " +
      "bulk. Where a shift requires a training, signup checks it — so nobody " +
      "finds out at the gate.",
    screenshot: screenshotPath("qualifications"),
    screenshotAlt:
      "A department's training list showing trainings with prerequisites, " +
      "expirations, and scheduled sessions.",
  },
  {
    id: "equipment",
    title: "Equipment",
    description:
      "Radios, vests, and everything else a department hands out are " +
      "tracked from inventory through checkout to return — including the " +
      "radio that came back damaged, and the one that did not come back.",
    screenshot: screenshotPath("equipment"),
    screenshotAlt:
      "A department's equipment inventory showing items available, checked " +
      "out, damaged, and written off.",
  },
  {
    id: "readiness",
    title: "Event readiness",
    description:
      "As an event approaches, each staff member sees one list of what is " +
      "still outstanding — the waiver to sign, the training to complete, the " +
      "shifts still open — each with a link that goes straight to closing " +
      "it.",
    screenshot: screenshotPath("readiness"),
    screenshotAlt:
      "A staff member's event readiness list naming their outstanding items " +
      "— an acknowledgment, a training, and open shift signups — with links " +
      "to resolve each.",
  },
];
