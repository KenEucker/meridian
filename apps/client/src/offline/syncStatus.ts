// Offline / sync connectivity-state model (M8.6).
//
// UI implementation contract section 11.13 (OfflineBanner) defines the allowed
// offline/sync states, and section 16.1 (Connectivity State Labels) defines the
// canonical label and meaning for each. This module is the single source of
// truth for those states so shared client surfaces render identical wording.
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

/**
 * What a background sync is doing, as far as the banner is concerned (M18.49).
 *
 * Not a fourth connectivity state and deliberately not one: contract 11.13 lists
 * seven and this maps into two of them. It exists because "is the node
 * reachable" and "is this device's data getting through" are different
 * questions, and the second one was answered by nothing until the offline read
 * set acquired its refresh triggers.
 */
export type SyncActivity =
  /** Nothing outstanding. Whatever the device holds is what it should hold. */
  | "settled"
  /** Something is waiting for connectivity that has not come back. */
  | "queued"
  /** The node was reached and the exchange failed. Somebody has to act. */
  | "failed";

/**
 * The banner state, once a sync activity is taken into account.
 *
 * Activity outranks connectivity, because connectivity is the *reason* and
 * activity is the *consequence*, and the consequence is what the person reading
 * the banner acts on. A device offline with a failed refresh is not helped by
 * "Offline but usable" — its surfaces are not usable, which is the fact that
 * needs saying (contract 16.2: show offline state where it affects current
 * work).
 *
 * `settled` returns the connectivity state untouched, which is what keeps the
 * banner silent for a device that is merely offline and working from a set it
 * holds. Routine field work is not interrupted with sync noise for a refresh
 * that will happen by itself when the node comes back.
 */
export function connectivityWithSyncActivity(
  connectivity: ConnectivityState,
  activity: SyncActivity,
): ConnectivityState {
  if (activity === "failed") {
    return "sync_failed";
  }

  if (activity === "queued") {
    return "sync_queued";
  }

  return connectivity;
}

/** One of contract 16.1A's four steps of notice, with the words to say. */
export interface NodeConnectionStatus {
  readonly label: string;
  readonly meaning: string;
  readonly tone: "unknown" | "failing" | "degraded" | "connected";
}

/**
 * How this device is doing against the node it syncs with (contract 16.1A).
 *
 * The four steps are a scale of notice, not a restatement of the seven states:
 * the canonical 16.1 meaning is carried as text alongside, because the contract
 * forbids colour carrying a state on its own.
 *
 * Both arguments are needed and neither is enough, which is the point. `state`
 * is one of the seven and cannot express "the node is not answering" — there is
 * no such state, and 16.1A lists that condition against the Failing step
 * anyway. `nodeIsUnreachable` supplies it, and it is deliberately narrower than
 * "nothing is reachable": true only where this device has a network and the node
 * on the other end of it says nothing.
 *
 * That narrowness is what separates the two ways to arrive at `offline_usable`.
 * A device with no network is Degraded — local work continues, the user can see
 * why, and reconnecting fixes it. A device whose network is fine and whose node
 * is silent is Failing, because that is the surprising case and the one somebody
 * has to act on.
 *
 * `null` is the Unknown step: no result yet. Startup only, and it exists so the
 * moment before the first answer is not spent claiming one.
 *
 * Lives here rather than in the shell because two surfaces report this — the
 * user button and its dropdown, and Device diagnostics — and they disagreed once
 * already.
 */
export function describeNodeConnection(
  state: ConnectivityState | null,
  nodeIsUnreachable: boolean,
): NodeConnectionStatus {
  if (state === null) {
    return {
      label: "Checking node connection",
      meaning: "This device has not heard from its node yet.",
      tone: "unknown",
    };
  }

  const descriptor = DESCRIPTORS[state];

  if (state === "sync_conflict" || state === "sync_failed") {
    return {
      label: "Node connection failing",
      meaning: descriptor.meaning,
      tone: "failing",
    };
  }

  if (nodeIsUnreachable) {
    return {
      label: "No node reachable",
      meaning: `This device cannot reach its node. ${descriptor.meaning}`,
      tone: "failing",
    };
  }

  if (state === "online") {
    return {
      label: "Connected and fully capable",
      meaning: descriptor.meaning,
      tone: "connected",
    };
  }

  return {
    label: "Node connection degraded",
    meaning: descriptor.meaning,
    tone: "degraded",
  };
}
