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
// Most features have two sides: the lead or organizer running it, and the
// staff member or applicant living it. Where both sides have a real surface,
// the entry carries two perspectives and the page offers a switch between
// them; where only one side has a surface today, the entry carries one and
// the micro-features speak for both roles. A perspective is never mocked up —
// a screenshot on this page is a promise the surface exists.
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
// illustrated with mockups.

export interface MarketingTourPerspective {
  /** Stable id; with the section id it names the screenshot asset. */
  readonly id: string;
  /** Who this side belongs to, as the switch labels it. */
  readonly label: string;
  /** The micro-features this side gets, one short claim each. */
  readonly microFeatures: readonly string[];
  /** Public asset path of the committed Northwood screenshot. */
  readonly screenshot: string;
  /** What the screenshot shows, for a reader who cannot see it. */
  readonly screenshotAlt: string;
}

export interface MarketingTourSection {
  /** Stable id, used for the section anchor and screenshot filenames. */
  readonly id: string;
  readonly title: string;
  /** What the feature does for an organization, in a couple of sentences. */
  readonly description: string;
  /** The sides of the feature, lead/organizer side first. One or two. */
  readonly perspectives: readonly MarketingTourPerspective[];
}

function screenshotPath(file: string): string {
  return `/assets/marketing/northwood/${file}.webp`;
}

export const MARKETING_FEATURE_TOUR: readonly MarketingTourSection[] = [
  {
    id: "applications",
    title: "Intake and applications",
    description:
      "Recruiting starts with a shareable application link, and applying " +
      "needs no account. Applications land in one review queue, and every " +
      "decision records who made it and when.",
    perspectives: [
      {
        id: "organizers",
        label: "For organizers",
        microFeatures: [
          "A public link opens recruiting — share it anywhere, and " +
            "opening it grants nothing",
          "One review queue across the event: approve, defer, or decline, " +
            "with a reason where you give one",
          "Review authority stays with organizers and staff coordinators — " +
            "seniority elsewhere does not open the queue",
          "Every decision is recorded with who made it and when",
        ],
        screenshot: screenshotPath("applications"),
        screenshotAlt:
          "The application review queue for an upcoming event, listing " +
          "applicants with their status — submitted, approved, rejected, and " +
          "deferred — and the controls to decide each one.",
      },
      {
        id: "applicants",
        label: "For applicants",
        microFeatures: [
          "Apply from a link in minutes: name, email, and what you are " +
            "drawn to",
          "Department interest is optional, and commits neither of you to " +
            "anything",
          "Check your applications later through an emailed link — still " +
            "no account, and the link signs you in to nothing",
          "Withdraw a submitted application yourself",
        ],
        screenshot: screenshotPath("applications-apply"),
        screenshotAlt:
          "The public application form for an upcoming event: legal name, " +
          "email address, an optional department interest, and a submit " +
          "button — no account required.",
      },
    ],
  },
  {
    id: "scheduling",
    title: "Shifts and signup",
    description:
      "Departments plan shifts with capacity, eligible teams, and " +
      "requirements. Staff sign themselves up, and a signup that would not " +
      "work is refused with the reason stated rather than silently.",
    perspectives: [
      {
        id: "leads",
        label: "For leads",
        microFeatures: [
          "Shifts carry capacity, eligible teams, time windows, and " +
            "requirements",
          "The Planning Table compares planned staffing and hours with " +
            "what is actually happening",
          "Identity-free by design: plan coverage without reading " +
            "anybody's personal records",
          "Team leads create and maintain their own team's shifts",
        ],
        screenshot: screenshotPath("scheduling-planning"),
        screenshotAlt:
          "The identity-free Planning Table comparing planned and actual " +
          "staffing: shift windows, capacity, active and completed counts, " +
          "planned against actual hours, and a timeline of scheduled shifts.",
      },
      {
        id: "staff",
        label: "For staff",
        microFeatures: [
          "Your own shift board of what your departments are running",
          "Sign yourself up from any device",
          "A refusal names its reason — a missing training, a lapsed " +
            "waiver, a full shift — instead of failing silently",
          "Signup closes and cutoffs move with the event when its dates " +
            "move",
        ],
        screenshot: screenshotPath("scheduling"),
        screenshotAlt:
          "A staff member's shift board showing open shifts across the " +
          "event with capacity, times, and signup controls, including one " +
          "shift already held.",
      },
    ],
  },
  {
    id: "operations",
    title: "Check-in, hours, and credits",
    description:
      "The Logistics Desk runs the day: check people in and out, add " +
      "somebody to a shift that is already running, and hand equipment " +
      "across the counter — while hours and credits record themselves.",
    perspectives: [
      {
        id: "window",
        label: "At the Logistics Window",
        microFeatures: [
          "Check in, check out, and no-show against the shift roster, " +
            "with search front and center",
          "Add somebody to a shift that is already running — the case " +
            "every event hits",
          "Equipment handed out and returned in the same motion as " +
            "check-in",
          "Hours are recorded as they happen and credits follow the " +
            "policies you set, with corrections audited",
        ],
        screenshot: screenshotPath("operations"),
        screenshotAlt:
          "The Logistics Desk with its search front and center, showing a " +
          "department's current shifts, presence counts, and equipment " +
          "ready for check-in and check-out.",
      },
    ],
  },
  {
    id: "incidents",
    title: "Incident management",
    description:
      "Field reports and incidents live in one command-scoped record, and " +
      "access follows Incident Command standing — not seniority.",
    perspectives: [
      {
        id: "command",
        label: "For incident command",
        microFeatures: [
          "A restricted workspace only Incident Command standing opens",
          "Priorities, statuses, types, responders, and locations on " +
            "every record",
          "Link related incidents and attach field reports as evidence",
          "Notes carry authorship; a struck note stays in the history " +
            "rather than vanishing",
        ],
        screenshot: screenshotPath("incidents"),
        screenshotAlt:
          "The incident list for a running event, showing incidents across " +
          "status and priority with their types, locations, and last " +
          "updates.",
      },
      {
        id: "field",
        label: "For staff in the field",
        microFeatures: [
          "Any staff member files a field report from where they stand",
          "Title, report, photos — finalized on submit, with no draft to " +
            "lose",
          "Works offline and submits itself when coverage returns",
          "A report can be taken for you over the radio, with author and " +
            "taker both recorded",
        ],
        screenshot: screenshotPath("incidents-field-report"),
        screenshotAlt:
          "The field report submission form a staff member files from the " +
          "field: the event and team it belongs to, a title, the report " +
          "text, and optional photos.",
      },
    ],
  },
  {
    id: "documents",
    title: "Policies and documents",
    description:
      "Policies, procedures, and event information are authored once, " +
      "published in versions, and readable anywhere — including offline.",
    perspectives: [
      {
        id: "authors",
        label: "For authors",
        microFeatures: [
          "Policies, procedures, and shared fragments move through " +
            "draft, published, and archived states",
          "Scope a document to the whole organization or to one " +
            "department",
          "Publishing creates a version, and acknowledgments record the " +
            "version each person accepted",
          "Event info pages assemble themselves from what is published",
        ],
        screenshot: screenshotPath("documents-authoring"),
        screenshotAlt:
          "The organizer document workspace listing policies and " +
          "procedures across draft, published, and archived states, with " +
          "edit, publish, and archive actions on each.",
      },
      {
        id: "staff",
        label: "For staff",
        microFeatures: [
          "Your library is exactly what is published to you",
          "Read anywhere, including offline in the field",
          "Acknowledge once, at a recorded version, and it stays " +
            "acknowledged",
          "Waivers are documents too — signed where you read them",
        ],
        screenshot: screenshotPath("documents"),
        screenshotAlt:
          "The staff document library listing an organization's published " +
          "policies and procedures with their scopes and versions.",
      },
    ],
  },
  {
    id: "qualifications",
    title: "Trainings and waivers",
    description:
      "Trainings carry prerequisites, expirations, and scheduled sessions. " +
      "Where a shift requires one, signup checks it — so nobody finds out " +
      "at the gate.",
    perspectives: [
      {
        id: "leads",
        label: "For leads",
        microFeatures: [
          "Trainings with prerequisites, expirations, and scheduled " +
            "sessions with capacity",
          "Record completions one at a time or import a spreadsheet",
          "A training-gated shift enforces itself at signup",
          "Superseded trainings are archived, and the record they earned " +
            "is kept",
        ],
        screenshot: screenshotPath("qualifications"),
        screenshotAlt:
          "A department's training workspace listing trainings with " +
          "schedules, expirations, prerequisites, and per-person status " +
          "columns.",
      },
      {
        id: "staff",
        label: "For staff",
        microFeatures: [
          "Your standing on every training: completed, signed up, or " +
            "still to do",
          "Session signup with the time, the place, and the seats left",
          "Prerequisites and expirations named before they surprise you",
        ],
        screenshot: screenshotPath("qualifications-staff"),
        screenshotAlt:
          "A staff member's view of their department's trainings: one " +
          "completed, one signed up with its scheduled session named, and " +
          "prerequisites shown.",
      },
    ],
  },
  {
    id: "equipment",
    title: "Equipment",
    description:
      "Radios, vests, and everything else a department hands out are " +
      "tracked from inventory through checkout to return — including the " +
      "radio that came back damaged, and the one that did not come back.",
    perspectives: [
      {
        id: "inventory",
        label: "At the window and in the books",
        microFeatures: [
          "Tracked items with asset tags and serials beside pooled stock " +
            "counted in bulk",
          "Checkout against a shift or the whole event; returns recorded " +
            "as returned, damaged, or missing",
          "Write-offs are audited rather than quietly deleted",
          "Scanner-driven lookup finds an item or its holder in one scan",
        ],
        screenshot: screenshotPath("equipment"),
        screenshotAlt:
          "A department's equipment inventory showing tracked radios and " +
          "vests and pooled stock, with items available, checked out, " +
          "damaged, and written off as missing.",
      },
    ],
  },
  {
    id: "readiness",
    title: "Event readiness",
    description:
      "As an event approaches, each staff member sees one list of what is " +
      "still outstanding — each item with a link that goes straight to " +
      "closing it.",
    perspectives: [
      {
        id: "staff",
        label: "For every staff member",
        microFeatures: [
          "Acknowledgments, waivers, trainings, and open shifts in one " +
            "list, each with its deadline",
          "Every item links straight to the place it is resolved",
          "Completed items stay visible and marked, so what registered " +
            "is legible",
          "Team leads also see coverage gaps for the teams they lead",
        ],
        screenshot: screenshotPath("readiness"),
        screenshotAlt:
          "A staff member's event readiness list naming their outstanding " +
          "items — open shift signups with deadlines — each with a link to " +
          "resolve it.",
      },
    ],
  },
];
