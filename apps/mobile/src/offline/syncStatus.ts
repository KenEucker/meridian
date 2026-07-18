// Offline / sync connectivity-state model (M8.6).
//
// UI implementation contract section 11.13 (OfflineBanner) defines the allowed
// offline/sync states, and section 16.1 (Connectivity State Labels) defines the
// canonical label and meaning for each. This module is the single source of
// truth for those states so the field app and kiosk render identical wording.
//
// The seven states map 1:1 to contract section 11.13, and the labels/meanings
// are taken verbatim from section 16.1:
//
//   online                -> "Online"              Central or expected sync target reachable
//   offline_usable        -> "Offline but usable"  Local work can continue
//   local_node_reachable  -> "Local node reachable" On-site node is reachable
//   central_unreachable   -> "Central unreachable" Local node may work but central sync is unavailable
//   sync_queued           -> "Queued"              Local actions are waiting to sync
//   sync_conflict         -> "Sync conflict"       Conflict needs handling
//   sync_failed           -> "Sync failed"         Sync failed and may require action
//
// Per section 16.2, offline state is shown only where it affects current work.
// `online` is the baseline "nothing affecting current work" state: the banner
// stays silent for it so routine field work is not interrupted with sync noise,
// and so the UI never implies current central truth when it cannot confirm the
// sync target is reachable.

/**
 * Offline/sync states allowed by UI implementation contract section 11.13, in
 * the order the contract lists them.
 */
export type ConnectivityState =
  | "online"
  | "offline_usable"
  | "local_node_reachable"
  | "central_unreachable"
  | "sync_queued"
  | "sync_conflict"
  | "sync_failed";

/**
 * Visual severity used for restrained but visible status treatment. State is
 * always carried by the canonical text label as well, never by tone alone
 * (accessibility checklist: "state is not conveyed by color alone").
 */
export type ConnectivityTone = "ok" | "info" | "warning" | "critical";

export interface ConnectivityStateDescriptor {
  readonly state: ConnectivityState;
  /** Canonical label from UI implementation contract section 16.1. */
  readonly label: string;
  /** Plain-language meaning from UI implementation contract section 16.1. */
  readonly meaning: string;
  readonly tone: ConnectivityTone;
  /**
   * Whether the state affects current work and should therefore be shown.
   * Only `online` is `false`; the banner stays silent for it (section 16.2).
   */
  readonly affectsWork: boolean;
}

/**
 * Order the states in the contract's own listing order so any surface that
 * iterates states (for example a preview or a test) stays deterministic.
 */
export const CONNECTIVITY_STATE_ORDER: readonly ConnectivityState[] = [
  "online",
  "offline_usable",
  "local_node_reachable",
  "central_unreachable",
  "sync_queued",
  "sync_conflict",
  "sync_failed",
];

const DESCRIPTORS: Readonly<
  Record<ConnectivityState, ConnectivityStateDescriptor>
> = {
  online: {
    state: "online",
    label: "Online",
    meaning: "Central or expected sync target reachable.",
    tone: "ok",
    affectsWork: false,
  },
  offline_usable: {
    state: "offline_usable",
    label: "Offline but usable",
    meaning: "Local work can continue.",
    tone: "info",
    affectsWork: true,
  },
  local_node_reachable: {
    state: "local_node_reachable",
    label: "Local node reachable",
    meaning: "On-site node is reachable.",
    tone: "info",
    affectsWork: true,
  },
  central_unreachable: {
    state: "central_unreachable",
    label: "Central unreachable",
    meaning: "Local node may work but central sync is unavailable.",
    tone: "warning",
    affectsWork: true,
  },
  sync_queued: {
    state: "sync_queued",
    label: "Queued",
    meaning: "Local actions are waiting to sync.",
    tone: "info",
    affectsWork: true,
  },
  sync_conflict: {
    state: "sync_conflict",
    label: "Sync conflict",
    meaning: "Conflict needs handling.",
    tone: "critical",
    affectsWork: true,
  },
  sync_failed: {
    state: "sync_failed",
    label: "Sync failed",
    meaning: "Sync failed and may require action.",
    tone: "critical",
    affectsWork: true,
  },
};

/** Resolve the canonical descriptor (label, meaning, tone) for a state. */
export function describeConnectivityState(
  state: ConnectivityState,
): ConnectivityStateDescriptor {
  return DESCRIPTORS[state];
}

/**
 * Whether an OfflineBanner should be shown for this state. Follows contract
 * section 16.2 ("show offline state only where it affects current work"): the
 * banner is silent when `online`.
 */
export function shouldShowOfflineBanner(state: ConnectivityState): boolean {
  return DESCRIPTORS[state].affectsWork;
}
