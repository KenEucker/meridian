// Per-artifact offline download status (CLIENT-025 through CLIENT-027;
// technical spec 9.7; UI implementation contract 16.4, 11.14).
//
// A device knows what it should hold because everything it should hold is
// named by a node response it already has: the session document names the
// caller and the organization whose branding assets apply, and the read set's
// `readiness` block (data/API 5.10) is the composed answer's own account of
// itself. This module composes those namings into one status a person can
// read — "3 of 5 downloaded", each artifact named with its state and last
// successful download.
//
// **The denominator comes only from node responses.** There is deliberately no
// list here of what Meridian ships. A device holding no session has been named
// nothing and is at 0 of 0; a caller entitled to no map package is at 2 of 2,
// not stuck forever at 2 of 3. The event map package joins the composition
// when a node response names one for this caller — nothing does yet, and until
// one does the honest denominator excludes it. Section 14 refuses a checklist
// of sections for `local cache complete` on exactly this reasoning.
//
// **The viewed-incident cache never enters the fraction.** Its whole is the
// user's own viewing rather than anything the node names (technical spec 19.2),
// so it reports a count — "4 viewed incidents cached" — where the Settings
// surface renders this status, and never a numerator or denominator here.
//
// **Where this is read.** The Settings/Readiness surface (contract 19A.2),
// beside the cached-permission state — not the shell, and never as a nag. The
// one shell-adjacent piece is the completion notice: when the device goes from
// holding less than the node named to holding all of it, a transient,
// self-dismissing Toast (contract 11.14) says so once. It never persists,
// never requires dismissal, and does not repeat for refresh checks that
// download nothing; it can fire again only after the device has been
// incomplete again.

import { computed, ref, watch } from "vue";

import {
  brandingAssetsDownloadedAt,
  brandingAssetsHeld,
  brandingState,
  loadBrandingProfile,
} from "@/branding/brandingProfile";
import {
  offlineReadSetRefreshStatus,
  refreshOfflineReadSet,
} from "@/offline/offlineReadSetRefresh";
import {
  offlineReadSetRevision,
  offlineReadSetStore,
  offlineReadSetStoredAt,
  offlineReadSetVerdict,
} from "@/offline/offlineReadSetRuntime";
import { clientSessionState } from "@/session/clientSession";

/** The artifacts node responses can name today. */
export const READ_SET_ARTIFACT_KEY = "offline-read-set";
export const BRANDING_ARTIFACT_KEY = "branding-assets";

/**
 * The granular read-set artifacts, keyed under this prefix.
 *
 * One row per named group rather than one "Offline read set" catch-all,
 * because the readout is a test surface as much as a status: "Staff directory
 * — 24 records stored" names the thing to go open with the node stopped, where
 * a single opaque row named nothing checkable. The grouping is presentation
 * over the readiness `counts` the node sent — the denominator is still
 * entirely the node's naming.
 */
export const READ_SET_GROUP_KEY_PREFIX = "read-set:";

interface ReadSetArtifactGroup {
  readonly key: string;
  /** The name a person reads, which is also the surface to test it on. */
  readonly name: string;
  /** Exact section names belonging to this group. */
  readonly sections: readonly string[];
  /** Section-name prefixes, so a section a role adds later lands with its kin. */
  readonly prefixes: readonly string[];
}

/**
 * In the order a person would test them: the shell first, then their own
 * pages, then the role desks.
 */
export const READ_SET_ARTIFACT_GROUPS: readonly ReadSetArtifactGroup[] =
  Object.freeze([
    {
      key: "initial-page-load",
      name: "Initial page load data",
      sections: [
        "staff",
        "staff_organization_statuses",
        "organizations",
        "departments",
        "teams",
        "department_memberships",
        "team_memberships",
        "events",
        "event_department_assignments",
      ],
      prefixes: [],
    },
    {
      key: "event-info",
      name: "Event info",
      sections: ["event_info"],
      prefixes: [],
    },
    {
      key: "staff-directory",
      name: "Staff directory",
      sections: [],
      prefixes: ["directory_"],
    },
    {
      key: "documents",
      name: "Policies & procedures",
      sections: [
        "policy_documents",
        "procedure_documents",
        "document_fragment_references",
        "document_fragments",
        "document_acknowledgment_requirements",
        "document_acknowledgments",
      ],
      prefixes: [],
    },
    {
      key: "schedule",
      name: "Shift board",
      sections: ["shifts", "shift_assignments"],
      prefixes: [],
    },
    {
      key: "field-reports",
      name: "Field reports",
      sections: ["field_report_form", "field_reports", "field_report_appends"],
      prefixes: [],
    },
    {
      key: "ims",
      name: "IMS entries",
      sections: ["ims_incidents"],
      prefixes: [],
    },
    {
      key: "logistics",
      name: "Logistics desk",
      sections: [],
      prefixes: ["logistics_"],
    },
    {
      key: "operations",
      name: "Operations center",
      sections: [],
      prefixes: ["operations_"],
    },
    {
      key: "planning",
      name: "Planning table",
      sections: [],
      prefixes: ["planning_"],
    },
    {
      key: "shift-lead",
      name: "Shift lead roster",
      sections: [],
      prefixes: ["shift_lead_"],
    },
    {
      key: "department-lead",
      name: "Department lead pack",
      sections: [],
      prefixes: ["department_lead_"],
    },
  ]);

/**
 * The row for sections no group claims — a section a newer node composes that
 * this build has no name for yet. Present so the composed set is accounted for
 * whole: a device must never hold data its own readout does not admit to.
 */
const OTHER_GROUP: ReadSetArtifactGroup = Object.freeze({
  key: "other",
  name: "Other event data",
  sections: [],
  prefixes: [],
});

function groupFor(section: string): ReadSetArtifactGroup {
  for (const group of READ_SET_ARTIFACT_GROUPS) {
    if (
      group.sections.includes(section) ||
      group.prefixes.some((prefix) => section.startsWith(prefix))
    ) {
      return group;
    }
  }

  return OTHER_GROUP;
}

export type DownloadArtifactState = "downloaded" | "pending" | "failed";

export interface DownloadArtifact {
  readonly key: string;
  /** The name a person reads on the Settings list. */
  readonly name: string;
  readonly state: DownloadArtifactState;
  /** Device time of the last successful download, or null when never. */
  readonly lastDownloadedAt: string | null;
  /** Why the artifact is failed or pending, when there is anything to say. */
  readonly detail: string | null;
}

export interface DownloadStatus {
  /** How many artifacts node responses have named for this device. */
  readonly named: number;
  readonly downloaded: number;
  /** Every named artifact is held. False while nothing has been named. */
  readonly complete: boolean;
  readonly artifacts: readonly DownloadArtifact[];
}

/**
 * The read-set rows: one per group the composed set names.
 *
 * Named by the node twice over. Before a set has ever arrived, the session
 * document is the naming — `GET /api/offline-read-set` composes an answer for
 * every signed-in caller (data/API 5.10) — and the device honestly shows one
 * pending "Event data" row, because what the answer will contain is the
 * node's to say. Once a set is held, its readiness `counts` are the naming,
 * and the row splits into the groups above, each carrying how many records
 * this device is holding for it.
 *
 * Downloaded means held *and* still servable under the 11A.4 window rule — a
 * set every surface already refuses is not one this readout may count. Failed
 * is the node having answered and the answer being unusable — a refusal or a
 * payload that is not a read set — which is the case that needs a repair path
 * rather than patience. The state is shared by every group row, because the
 * set arrives, expires, and refreshes whole.
 */
function readSetArtifacts(now: Date): DownloadArtifact[] {
  const verdict = offlineReadSetVerdict(now);
  const storedAt = offlineReadSetStoredAt();
  const refresh = offlineReadSetRefreshStatus.value;
  const counts = offlineReadSetStore.held()?.set.readiness.counts ?? null;

  let state: DownloadArtifactState;
  let detail: string | null = null;

  if (verdict.access === "granted") {
    state = "downloaded";
  } else if (refresh.outcome === "refused" || refresh.outcome === "unusable") {
    state = "failed";
    detail =
      refresh.detail ??
      "The node's last answer could not be stored. Retry the download.";
  } else {
    state = "pending";
    detail =
      storedAt === null
        ? "Not downloaded yet. It downloads when the node is reachable."
        : "The stored copy can no longer be served. It refreshes when the node is reachable.";
  }

  if (counts === null) {
    return [
      {
        key: READ_SET_ARTIFACT_KEY,
        name: "Event data",
        state,
        lastDownloadedAt: storedAt,
        detail,
      },
    ];
  }

  const records = new Map<string, number>();

  for (const [section, rows] of Object.entries(counts)) {
    const group = groupFor(section);

    records.set(group.key, (records.get(group.key) ?? 0) + rows);
  }

  return [...READ_SET_ARTIFACT_GROUPS, OTHER_GROUP]
    .filter((group) => records.has(group.key))
    .map((group) => {
      const stored = records.get(group.key) ?? 0;

      return {
        key: `${READ_SET_GROUP_KEY_PREFIX}${group.key}`,
        name: group.name,
        state,
        lastDownloadedAt: storedAt,
        detail:
          state === "downloaded"
            ? `${stored} ${stored === 1 ? "record" : "records"} stored.`
            : detail,
      };
    });
}

/**
 * The branding assets' row, for the organization the session resolved.
 *
 * Held means the profile is applied this session or cached from an earlier one
 * (BRAND-022) — either way the device renders its organization offline. The
 * stamp can be null under an entry written before the stamp existed; the row
 * still reads downloaded, with no moment to name.
 */
function brandingArtifact(organizationId: string): DownloadArtifact {
  // Reactive on the applied profile, so a computed built on this recomputes
  // when branding resolves.
  void brandingState.profile.organization_id;

  const held = brandingAssetsHeld(organizationId);

  return {
    key: BRANDING_ARTIFACT_KEY,
    name: "Branding assets",
    state: held ? "downloaded" : "pending",
    lastDownloadedAt: held ? brandingAssetsDownloadedAt(organizationId) : null,
    detail: held
      ? null
      : "Not downloaded yet. It downloads when the node is reachable.",
  };
}

/**
 * Compose the download status from what node responses have named.
 *
 * Evaluated against the moment it is asked, like the verdicts it reads: a
 * readout left open while a set expires stops counting it where it stands.
 */
export function resolveDownloadStatus(now: Date = new Date()): DownloadStatus {
  // Reactive dependencies, so computeds and watchers built on this move when a
  // refresh lands, a set is dropped, or the session resolves.
  void offlineReadSetRevision.value;

  const document = clientSessionState.document;

  if (document === null) {
    // No session, nothing named, and honestly so: 0 of 0 is not complete.
    return { named: 0, downloaded: 0, complete: false, artifacts: [] };
  }

  const artifacts: DownloadArtifact[] = readSetArtifacts(now);
  const organizationId = document.context.organization_id;

  if (organizationId !== null) {
    artifacts.push(brandingArtifact(organizationId));
  }

  // The event map package joins here when a node response names one for this
  // caller (technical spec 9.3, 9.7). No response carries that naming yet, so
  // it is absent from the denominator rather than invented on the client.

  const downloaded = artifacts.filter(
    (artifact) => artifact.state === "downloaded",
  ).length;

  return {
    named: artifacts.length,
    downloaded,
    complete: artifacts.length > 0 && downloaded === artifacts.length,
    artifacts,
  };
}

/** The status, as a dependency-tracked computed for the Settings surface. */
export const downloadStatus = computed<DownloadStatus>(() =>
  resolveDownloadStatus(),
);

/**
 * The repair path for an artifact that failed or stalled (contract 16.4): ask
 * again, through the same machinery that fetches it in the first place. Silent
 * incompleteness is the defect this control exists to prevent.
 */
export async function retryArtifactDownload(key: string): Promise<void> {
  if (
    key === READ_SET_ARTIFACT_KEY ||
    key.startsWith(READ_SET_GROUP_KEY_PREFIX)
  ) {
    // Every group row is the one set, so retrying any of them is one refresh.
    await refreshOfflineReadSet("requested");

    return;
  }

  if (key === BRANDING_ARTIFACT_KEY) {
    const organizationId =
      clientSessionState.document?.context.organization_id ?? null;

    if (organizationId !== null) {
      await loadBrandingProfile(organizationId);
    }
  }
}

/** The completion notice's one sentence (contract 16.4). */
export const DOWNLOAD_COMPLETE_NOTICE = "All available event data is downloaded.";

/** How long the notice stands before dismissing itself. */
export const DOWNLOAD_COMPLETE_NOTICE_MS = 6000;

const noticeVisible = ref(false);

/** Whether the transient completion notice is showing right now. */
export const downloadCompleteNoticeVisible = computed(() => noticeVisible.value);

/**
 * The previous observation's completeness, which is what makes the notice a
 * *transition* rather than a state: null until something has been observed, so
 * a device that boots already complete says nothing.
 */
let previousComplete: boolean | null = null;

let dismissTimer: ReturnType<typeof setTimeout> | null = null;

function showCompletionNotice(): void {
  noticeVisible.value = true;

  if (dismissTimer !== null) {
    clearTimeout(dismissTimer);
  }

  dismissTimer = setTimeout(() => {
    noticeVisible.value = false;
    dismissTimer = null;
  }, DOWNLOAD_COMPLETE_NOTICE_MS);
}

/**
 * Record one observation of the status; returns whether the notice fired.
 *
 * Fires only on incomplete → complete. A refresh check that downloads nothing
 * observes complete → complete and stays quiet; the notice can fire again only
 * after the device has been incomplete in between — a context switch, a newly
 * published artifact, an actual re-download (CLIENT-027).
 */
export function recordDownloadStatusObservation(status: DownloadStatus): boolean {
  const fired = status.complete && previousComplete === false;

  previousComplete = status.complete;

  if (fired) {
    showCompletionNotice();
  }

  return fired;
}

/**
 * Watch the status for the life of the application.
 *
 * Installed by `main.ts` rather than by a screen, because the transition it
 * watches for — the last outstanding artifact landing — happens wherever the
 * device is standing, not on a surface somebody has open. Returns its own
 * teardown, which the specs use.
 */
export function installDownloadStatusObserver(): () => void {
  return watch(
    downloadStatus,
    (status) => {
      recordDownloadStatusObservation(status);
    },
    { immediate: true },
  );
}

/** Reset observation state between tests. */
export function resetDownloadStatusForTests(): void {
  previousComplete = null;
  noticeVisible.value = false;

  if (dismissTimer !== null) {
    clearTimeout(dismissTimer);
    dismissTimer = null;
  }
}
