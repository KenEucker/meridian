// Readiness checklist model (M8.5).
//
// Technical spec section 14 (Readiness) defines a per user/device/event
// checklist that is advisory only, visible to the user, and never visible to
// organizers. It does not expire automatically and the app must avoid nagging:
// users are encouraged, but not required, to prepare their devices. The
// checklist items, in the order the spec lists them, are:
//
//   logged in
//   device trusted
//   event selected
//   local cache complete
//   encryption active
//   last sync completed
//   trusted server known
//   device signing available
//
// This module turns the readiness signals that exist today into that checklist.
// Encryption (M8.3), device signing (M8.4), and the node this device works
// against each have a real probe, and since M16.5 the session document answers
// two more: `logged in` and `event selected` are facts about the session the
// client holds, not features waiting to be built. The remaining items — device
// trust, local cache, and last sync — still depend on work owned by later
// Alpha 1 milestones, and rather than invent behavior they are reported
// honestly as `pending` until their signal is wired in by the owning task.

import { nodeConnection, type NodeConnection } from "@/app/nodeConnection";
import {
  checkDeviceSigningReadiness,
  type DeviceSigningReadiness,
} from "@/readiness/deviceSigning";
import {
  checkLocalEncryptionReadiness,
  type LocalEncryptionReadiness,
} from "@/readiness/localEncryption";
import { clientSessionState } from "@/session/clientSession";

/**
 * Stable identifiers for the technical spec section 14 readiness items, in the
 * order the spec lists them.
 */
export type ReadinessItemKey =
  | "loggedIn"
  | "deviceTrusted"
  | "eventSelected"
  | "localCacheComplete"
  | "encryptionActive"
  | "lastSyncCompleted"
  | "trustedServerKnown"
  | "deviceSigningAvailable";

/**
 * Advisory state for a single readiness item.
 *
 * - `ready`: the signal confirms the item is satisfied.
 * - `not-ready`: the signal confirms the item is not satisfied.
 * - `pending`: no signal is available yet in this build (the backing feature is
 *   owned by a later Alpha 1 milestone). Pending is not a failure; readiness is
 *   advisory and the app must not nag.
 */
export type ReadinessItemStatus = "ready" | "not-ready" | "pending";

export interface ReadinessChecklistItem {
  readonly key: ReadinessItemKey;
  /** Human-readable label matching the technical spec section 14 wording. */
  readonly label: string;
  readonly status: ReadinessItemStatus;
  /** Short explanation for the current status, or `null` when none applies. */
  readonly detail: string | null;
}

export interface ReadinessSummary {
  readonly ready: number;
  readonly notReady: number;
  readonly pending: number;
  readonly total: number;
}

/**
 * What the client's session says, reduced to the two checklist items it
 * answers.
 *
 * Reduced rather than passed whole because readiness has no business reasoning
 * about a session document. It asks two questions — is somebody signed in, and
 * is an event in effect — and everything that decides those answers is settled
 * before it gets here.
 */
export interface ReadinessSessionSignal {
  /** Whether the client holds permissions it may act on right now. */
  readonly signedIn: boolean;
  /** Who they belong to, when a document is held. */
  readonly userName: string | null;
  /** True when they came from the durable copy rather than from the node. */
  readonly cached: boolean;
  /**
   * Why a held session is refused, when one is. A device holding an expired
   * document is not signed in for readiness purposes, and saying which of the
   * two is the case is the difference between "sign in" and "reconnect".
   */
  readonly refusedDetail: string | null;
  /** The event the session resolved at, or null when none is in effect. */
  readonly eventId: string | null;
  readonly eventLabel: string | null;
  /** Whether the node this session came from is locked to that event. */
  readonly nodeLocked: boolean;
}

/**
 * Readiness signals available today. Local encryption (M8.3), device signing
 * (M8.4), the node connection, and the session (M16.5) are wired; the remaining
 * items resolve to `pending`.
 */
export interface ReadinessChecklistInputs {
  readonly localEncryption: LocalEncryptionReadiness;
  readonly deviceSigning: DeviceSigningReadiness;
  readonly node: NodeConnection;
  readonly session: ReadinessSessionSignal;
}

const READINESS_ITEM_ORDER: readonly ReadinessItemKey[] = [
  "loggedIn",
  "deviceTrusted",
  "eventSelected",
  "localCacheComplete",
  "encryptionActive",
  "lastSyncCompleted",
  "trustedServerKnown",
  "deviceSigningAvailable",
];

const READINESS_ITEM_LABEL: Readonly<Record<ReadinessItemKey, string>> = {
  loggedIn: "Logged in",
  deviceTrusted: "Device trusted",
  eventSelected: "Event selected",
  localCacheComplete: "Local cache complete",
  encryptionActive: "Encryption active",
  lastSyncCompleted: "Last sync completed",
  trustedServerKnown: "Trusted server known",
  deviceSigningAvailable: "Device signing available",
};

/**
 * Detail shown for items whose backing signal is not implemented yet. Kept
 * neutral and honest so the checklist never implies a failure or a false pass.
 */
const PENDING_DETAIL = "Not available yet in this build.";

/** Items that resolve to `pending` until their owning milestone wires them up. */
const PENDING_ITEMS: readonly ReadinessItemKey[] = [
  "deviceTrusted",
  "localCacheComplete",
  "lastSyncCompleted",
];

/**
 * `logged in` is whether this device holds permissions it may act on.
 *
 * Three states rather than two, because a device that signed in last week and
 * whose event window has since closed is neither signed in nor signed out, and
 * telling those apart is the difference between "sign in" and "reconnect". A
 * cached session is ready: working from the last answer the node gave, inside
 * the event window, is the normal state of a device on site, and marking it
 * short of ready would be the nagging section 14 rules out.
 */
function loggedInItem(session: ReadinessSessionSignal): ReadinessChecklistItem {
  if (session.refusedDetail !== null) {
    return toItem("loggedIn", "not-ready", session.refusedDetail);
  }

  if (!session.signedIn) {
    return toItem(
      "loggedIn",
      "not-ready",
      "No session on this device. Sign in to prepare it.",
    );
  }

  const who = session.userName ?? "this device's user";

  return toItem(
    "loggedIn",
    "ready",
    session.cached
      ? `Signed in as ${who}, from the permissions this device last received.`
      : `Signed in as ${who}.`,
  );
}

/**
 * `event selected` is whether an event is in effect, however it got there.
 *
 * On a locked node nobody selected anything — the node answers for its event
 * and there is nothing to pick between (technical spec 11A.3) — and the item is
 * ready all the same. The checklist asks whether the device knows which event
 * it is working, not whether a human chose it from a list.
 */
function eventSelectedItem(
  session: ReadinessSessionSignal,
): ReadinessChecklistItem {
  if (session.eventId === null) {
    return toItem(
      "eventSelected",
      "not-ready",
      session.signedIn
        ? "No event is in effect on this device."
        : "Sign in to resolve the event this device works.",
    );
  }

  const label = session.eventLabel ?? "The event this node answers for";

  return toItem(
    "eventSelected",
    "ready",
    session.nodeLocked ? `${label}. This node is locked to it.` : label,
  );
}

/**
 * `trusted server known` is about knowing which node this device works
 * against, which is now a real signal: a device is either pointed at a node or
 * falling back to a development default. Establishing *trust* with that node
 * is device trust, which is the separate `deviceTrusted` item and is still
 * pending.
 */
function nodeItem(node: NodeConnection): ReadinessChecklistItem {
  if (node.source === "default") {
    return toItem(
      "trustedServerKnown",
      "not-ready",
      "No node is configured. This device is using the local development default.",
    );
  }

  if (node.overridesServingNode) {
    return toItem(
      "trustedServerKnown",
      "ready",
      `Set to ${node.url}, which is not the node that served this app.`,
    );
  }

  return toItem("trustedServerKnown", "ready", node.url);
}

function toItem(
  key: ReadinessItemKey,
  status: ReadinessItemStatus,
  detail: string | null,
): ReadinessChecklistItem {
  return { key, label: READINESS_ITEM_LABEL[key], status, detail };
}

function capabilityItem(
  key: ReadinessItemKey,
  readiness: LocalEncryptionReadiness | DeviceSigningReadiness,
): ReadinessChecklistItem {
  return toItem(
    key,
    readiness.available ? "ready" : "not-ready",
    readiness.reason,
  );
}

/**
 * Build the technical spec section 14 readiness checklist from the signals that
 * exist today. Items without an implemented signal are reported as `pending`.
 */
export function buildReadinessChecklist(
  inputs: ReadinessChecklistInputs,
): ReadinessChecklistItem[] {
  return READINESS_ITEM_ORDER.map((key) => {
    if (key === "encryptionActive") {
      return capabilityItem(key, inputs.localEncryption);
    }
    if (key === "deviceSigningAvailable") {
      return capabilityItem(key, inputs.deviceSigning);
    }
    if (key === "trustedServerKnown") {
      return nodeItem(inputs.node);
    }
    if (key === "loggedIn") {
      return loggedInItem(inputs.session);
    }
    if (key === "eventSelected") {
      return eventSelectedItem(inputs.session);
    }
    if (PENDING_ITEMS.includes(key)) {
      return toItem(key, "pending", PENDING_DETAIL);
    }
    // Unreachable: every key is either a capability item or pending.
    return toItem(key, "pending", PENDING_DETAIL);
  });
}

/** Count readiness items by status for an advisory, non-nagging summary. */
export function summarizeReadiness(
  items: readonly ReadinessChecklistItem[],
): ReadinessSummary {
  const summary = { ready: 0, notReady: 0, pending: 0, total: items.length };

  for (const item of items) {
    if (item.status === "ready") {
      summary.ready += 1;
    } else if (item.status === "not-ready") {
      summary.notReady += 1;
    } else {
      summary.pending += 1;
    }
  }

  return summary;
}

/**
 * Minimal global-like scope shared by the local-encryption and device-signing
 * probes, so a single injected scope can drive both. Every member is optional
 * and the probes default to `globalThis` when no scope is supplied.
 */
export interface ReadinessProbeScope {
  readonly isSecureContext?: boolean;
  readonly crypto?: {
    readonly subtle?: {
      readonly generateKey?: unknown;
      readonly encrypt?: unknown;
      readonly decrypt?: unknown;
      readonly sign?: unknown;
      readonly verify?: unknown;
    };
  };
  readonly indexedDB?: unknown;
}

/**
 * Reduce the session this client holds to the two items readiness asks about.
 *
 * Read out of `clientSessionState` rather than through `sessionContext`, because
 * both readings agree on the answer and this one keeps the readiness screen from
 * depending on the context module's switcher machinery. The event is taken only
 * while access is granted: a document whose event window has closed still names
 * an event, and reporting it as selected would be a green check against a
 * context the client will not act on.
 */
export function resolveReadinessSessionSignal(): ReadinessSessionSignal {
  const document = clientSessionState.document;
  const status = clientSessionState.status;
  const granted = status === "live" || status === "cached";

  if (document === null) {
    return {
      signedIn: false,
      userName: null,
      cached: false,
      refusedDetail: null,
      eventId: null,
      eventLabel: null,
      nodeLocked: false,
    };
  }

  const eventId = granted ? document.context.event_id : null;
  const event =
    eventId === null
      ? null
      : (document.events.find((candidate) => candidate.id === eventId) ?? null);

  return {
    signedIn: granted,
    userName: document.user.name,
    cached: status === "cached",
    refusedDetail: granted ? null : describeRefusedSession(),
    eventId,
    eventLabel: event?.name ?? null,
    nodeLocked: document.context.node_locked,
  };
}

/**
 * Why a held session is not being acted on, in the same words the permissions
 * notice uses. Two surfaces saying the same state differently is two states as
 * far as anyone reading them is concerned.
 */
function describeRefusedSession(): string {
  switch (clientSessionState.refreshReason) {
    case "event_window_ended":
      return "The event this device cached its permissions for has ended. Reconnect to the node to continue.";
    case "no_event_context":
      return "This device holds no event context. Reconnect to the node to continue.";
    default:
      return "This device cannot confirm the event its permissions were cached for. Reconnect to the node to continue.";
  }
}

/**
 * Probe the current client scope and build the readiness checklist in one call.
 * Defaults to the real platform (`globalThis`) while tests inject a scope.
 */
export function resolveReadinessChecklist(
  scope?: ReadinessProbeScope,
): ReadinessChecklistItem[] {
  return buildReadinessChecklist({
    localEncryption: checkLocalEncryptionReadiness(scope),
    deviceSigning: checkDeviceSigningReadiness(scope),
    node: nodeConnection.value,
    session: resolveReadinessSessionSignal(),
  });
}
