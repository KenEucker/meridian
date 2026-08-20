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
// client holds, not features waiting to be built.
//
// `device trusted` is answered for both kinds of device. Technical spec 13.1
// calls a shared workstation "a special kind of trusted device", and since
// M18.32 the node publishes that trust through the Kiosk pinned-context read. A
// personal device's trust lives in `device_trusts` (data/API 12.2), and the
// session document now carries the node's answer for the device this session's
// token is bound to — which is what the item reads, rather than inferring trust
// from the token itself. A token proves this device may call the node; trust is
// the separate six-week relationship AUTH-024 keeps on its own lifetime, and
// reading one as the other would be a false pass on the item least worth faking.
//
// `local cache complete` and `last sync completed` were pending until the work
// behind them landed, and it has. The read set (M18.46 through M18.50) is what a
// complete local cache means, and the two directions of sync — the set coming
// down and the command outbox going up — are what "last sync completed" means
// together. Both are read from the modules that own them rather than recomputed
// here; this file decides only how to say what they report.

import { nodeConnection, type NodeConnection } from "@/app/nodeConnection";
import { offlineReadSetRefreshStatus } from "@/offline/offlineReadSetRefresh";
import {
  offlineReadSetStoredAt,
  offlineReadSetVerdict,
} from "@/offline/offlineReadSetRuntime";
import {
  commandOutbox,
  commandOutboxRevision,
} from "@/outbox/commandOutboxRuntime";
import {
  checkDeviceSigningReadiness,
  type DeviceSigningReadiness,
} from "@/readiness/deviceSigning";
import {
  checkLocalEncryptionReadiness,
  type LocalEncryptionReadiness,
} from "@/readiness/localEncryption";
import { clientSessionState } from "@/session/clientSession";
import { kioskContextState } from "@/session/kioskContext";
import { sharedWorkstationId } from "@/session/workstationIdentity";

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
 * What this machine is, as far as device trust goes (M18.32; technical spec
 * 13.1).
 *
 * `isSharedWorkstation` is whether this machine claims to be one at all. A
 * personal device does not, and is answered by {@link ReadinessDeviceSignal}
 * instead — "not a shared workstation" was never a trust problem.
 */
export interface ReadinessWorkstationSignal {
  /** Whether this machine is configured as a shared workstation. */
  readonly isSharedWorkstation: boolean;
  /** Whether the node vouches for it, or null when the node has not said. */
  readonly trusted: boolean | null;
  readonly workstationName: string | null;
  /** True when `trusted` is the node's last answer rather than a fresh one. */
  readonly fromStoredAnswer: boolean;
}

/**
 * The node's answer about the device this session's credential is bound to
 * (AUTH-021, AUTH-024; technical spec 12.2; data/API 12.2).
 *
 * `named` is false when the session carries no device block at all: a
 * shared-workstation session names no device, and so does a document stored by
 * a build from before the node published one. Both are "nothing was asked",
 * which is a different answer from "asked and refused".
 */
export interface ReadinessDeviceSignal {
  readonly named: boolean;
  readonly trusted: boolean;
  readonly state: "trusted" | "untrusted" | "expired" | "revoked";
  readonly label: string | null;
  readonly trustedUntil: string | null;
  /** True when the answer came from the durable copy rather than the node. */
  readonly cached: boolean;
}

/**
 * What this device holds of its authorized read set (M18.46 through M18.50;
 * CLIENT-021; technical spec 9.3, 11A.4).
 *
 * "Complete" is the set the node composed *for this caller*, not a fixed list of
 * sections: the set is composed per request from the caller's own effective
 * roles and the organization's active modules, so a device holding no Logistics
 * index may be holding exactly what it should. Anything else would report a
 * staff member's correct cache as incomplete forever.
 */
export interface ReadinessCacheSignal {
  /** Whether a set is held and may still be served (the 11A.4 window rule). */
  readonly usable: boolean;
  /** Whether anything is held at all, usable or not. */
  readonly held: boolean;
  /** Why it may not be served, when it may not. */
  readonly reason: "nothing_held" | "event_window_ended" | "no_event_context" | null;
  /** When the device took delivery of what it holds. */
  readonly storedAt: string | null;
}

/**
 * Both directions of sync, which is what makes "last sync completed" one item
 * rather than two (M18.49 for the pull, M16.10 for the push).
 *
 * A device whose read set refreshed a minute ago and whose outbox holds three
 * unsent check-ins has not completed a sync in any sense its owner cares about,
 * and a checklist that reported it ready would be answering a question nobody
 * asked.
 */
export interface ReadinessSyncSignal {
  /** Device time of the last read-set refresh that reached the node. */
  readonly refreshedAt: string | null;
  /** Device time of the last refresh attempt that did not. */
  readonly failedAt: string | null;
  /** Commands this device is still the only copy of. */
  readonly unsentCommands: number;
  /** Commands the node refused, waiting for somebody to deal with them. */
  readonly rejectedCommands: number;
}

/**
 * Every readiness signal, all eight items' worth. Local encryption (M8.3),
 * device signing (M8.4), the node connection, the session (M16.5),
 * shared-workstation trust (M18.32), personal device trust, the offline read
 * set, and the two directions of sync.
 */
export interface ReadinessChecklistInputs {
  readonly localEncryption: LocalEncryptionReadiness;
  readonly deviceSigning: DeviceSigningReadiness;
  readonly node: NodeConnection;
  readonly session: ReadinessSessionSignal;
  readonly workstation: ReadinessWorkstationSignal;
  readonly device: ReadinessDeviceSignal;
  readonly cache: ReadinessCacheSignal;
  readonly sync: ReadinessSyncSignal;
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
 *
 * No item resolves here any more. It stays because `pending` is a state the
 * checklist keeps for a signal that has not answered *yet* — a workstation
 * mid-boot, a session naming no device — and those are cases with their own
 * words, not this one.
 */
const PENDING_DETAIL = "Not available yet in this build.";

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
 * `device trusted` (M18.32; technical spec 13.1; data/API 12.2).
 *
 * Two kinds of device, and only one of them can answer today.
 *
 * A **shared workstation** is "a special kind of trusted device" (13.1), and its
 * trust is published: the pinned-context read answers for a trusted, unrevoked
 * workstation and 404s for anything else, so a machine that got an answer has
 * been vouched for by name. A machine that claims to be a shared workstation and
 * gets refused is genuinely not ready — that is a technician's problem, and the
 * one case on this checklist where "device trusted" can honestly fail.
 *
 * A **personal device** is answered by the session document, which now carries
 * the node's own verdict for the device its token is bound to. The token is
 * still not read as a substitute for that verdict: a token proves this device
 * may call the node, and trust is the separate six-week relationship AUTH-024
 * keeps on its own lifetime. Reporting the token as trust would be a false pass
 * on the item least worth faking.
 *
 * A stored answer still reads ready. The node being unreachable is the ordinary
 * state of a machine on site, readiness is advisory and must not nag (spec 14),
 * and the node enforces trust on every request regardless of what this says. The
 * detail names that it is the stored answer so the reader knows which it is.
 */
function deviceTrustedItem(
  workstation: ReadinessWorkstationSignal,
  device: ReadinessDeviceSignal,
): ReadinessChecklistItem {
  if (!workstation.isSharedWorkstation) {
    return personalDeviceTrustedItem(device);
  }

  if (workstation.trusted === null) {
    return toItem(
      "deviceTrusted",
      "pending",
      "This workstation has not been confirmed with the node yet.",
    );
  }

  if (!workstation.trusted) {
    return toItem(
      "deviceTrusted",
      "not-ready",
      "This node holds no trusted shared workstation with this machine's identifier.",
    );
  }

  const name = workstation.workstationName;
  const label =
    name === null || name === ""
      ? "Trusted shared workstation."
      : `Trusted shared workstation ${name}.`;

  return toItem(
    "deviceTrusted",
    "ready",
    workstation.fromStoredAnswer
      ? `${label} This is the node's last answer; it could not be reached to confirm.`
      : label,
  );
}

/**
 * `device trusted` for a personal device (AUTH-021, AUTH-024; data/API 12.2).
 *
 * The node establishes trust at sign-in and gives it six weeks from the last
 * one, so the three ways it can be absent are three different sentences for the
 * person reading them: never established, lapsed — which signing in again
 * fixes — and revoked, which it does not.
 *
 * A session naming no device is `pending` rather than a failure. That is the
 * shared-workstation session key, and the document a build from before the node
 * published this stored; neither is a device that failed a check.
 */
function personalDeviceTrustedItem(
  device: ReadinessDeviceSignal,
): ReadinessChecklistItem {
  if (!device.named) {
    return toItem(
      "deviceTrusted",
      "pending",
      "This session names no device to report trust for.",
    );
  }

  const named = device.label === null || device.label === "" ? "This device" : device.label;

  if (device.trusted) {
    const until =
      device.trustedUntil === null
        ? `${named} is trusted for your account.`
        : `${named} is trusted for your account until ${device.trustedUntil}.`;

    return toItem(
      "deviceTrusted",
      "ready",
      device.cached
        ? `${until} This is the node's last answer; it could not be reached to confirm.`
        : until,
    );
  }

  switch (device.state) {
    case "revoked":
      return toItem(
        "deviceTrusted",
        "not-ready",
        `${named} has been revoked for your account. An administrator has to restore it.`,
      );
    case "expired":
      return toItem(
        "deviceTrusted",
        "not-ready",
        `Trust for ${named} has lapsed. Signing in again renews it.`,
      );
    default:
      return toItem(
        "deviceTrusted",
        "not-ready",
        `${named} is not trusted for your account yet. Signing in from it establishes trust.`,
      );
  }
}

/**
 * `local cache complete` (M18.46 through M18.50; CLIENT-021; technical spec 9.3,
 * 11A.4).
 *
 * Complete means the device holds the set the node composed for this caller and
 * may still serve it. It deliberately does not mean "holds every section": the
 * set is composed per request from the caller's own effective roles and the
 * organization's active modules (9.5), so a staff member with no Logistics scope
 * holding no Logistics index is holding a complete cache. Checking for sections
 * would report their correct device as incomplete for the life of the install.
 *
 * A set past its event window reads not-ready rather than ready-but-stale,
 * because that is what the store does with it — 11A.4 refuses to serve one, so
 * every surface on the device is already answering as though it holds nothing.
 */
function localCacheItem(cache: ReadinessCacheSignal): ReadinessChecklistItem {
  if (cache.usable) {
    return toItem(
      "localCacheComplete",
      "ready",
      cache.storedAt === null
        ? "This device holds the data it is authorized to work from offline."
        : `Stored ${cache.storedAt}. This device holds the data it is authorized to work from offline.`,
    );
  }

  if (!cache.held || cache.reason === "nothing_held") {
    return toItem(
      "localCacheComplete",
      "not-ready",
      "This device holds no offline copy yet. Connect to the node to fetch one.",
    );
  }

  if (cache.reason === "event_window_ended") {
    return toItem(
      "localCacheComplete",
      "not-ready",
      "The copy this device holds is for an event that has ended. Connect to the node to refresh it.",
    );
  }

  return toItem(
    "localCacheComplete",
    "not-ready",
    "This device cannot confirm which event its offline copy belongs to. Connect to the node to refresh it.",
  );
}

/**
 * `last sync completed`, in both directions (M18.49 for the pull, M16.10 for the
 * push).
 *
 * One item covering two flows, because the person reading it is asking one
 * question: is this device's work where it needs to be. A read set refreshed a
 * minute ago says nothing about three check-ins still sitting in the outbox, and
 * a device with an empty outbox that has not refreshed since last Tuesday is
 * working from a week-old roster.
 *
 * Unsent work is `not-ready` and says how much. A refusal is `not-ready` too and
 * is the more urgent of the two, because it will not clear on its own: the
 * outbox notice is where it gets resolved, and this item's job is to say it is
 * there. Neither is a nag — section 14 rules those out, and stating a count once
 * on a screen somebody opened is not one.
 */
function lastSyncItem(sync: ReadinessSyncSignal): ReadinessChecklistItem {
  if (sync.rejectedCommands > 0) {
    return toItem(
      "lastSyncCompleted",
      "not-ready",
      `${countOf(sync.rejectedCommands, "action")} the node refused ${sync.rejectedCommands === 1 ? "is" : "are"} waiting for you to deal with ${sync.rejectedCommands === 1 ? "it" : "them"}.`,
    );
  }

  if (sync.unsentCommands > 0) {
    return toItem(
      "lastSyncCompleted",
      "not-ready",
      `${countOf(sync.unsentCommands, "action")} recorded on this device ${sync.unsentCommands === 1 ? "has" : "have"} not reached the node yet.`,
    );
  }

  if (sync.refreshedAt === null) {
    return toItem(
      "lastSyncCompleted",
      "not-ready",
      sync.failedAt === null
        ? "This device has not synced with the node yet."
        : `The last attempt to sync did not reach the node (${sync.failedAt}).`,
    );
  }

  const detail = `Everything recorded here has been sent. Last synced ${sync.refreshedAt}.`;

  return toItem(
    "lastSyncCompleted",
    "ready",
    sync.failedAt === null
      ? detail
      : `${detail} A later attempt did not reach the node.`,
  );
}

/** "1 action" / "3 actions", so a count reads as a sentence rather than a stat. */
function countOf(count: number, noun: string): string {
  return count === 1 ? `1 ${noun}` : `${count} ${noun}s`;
}

/**
 * `trusted server known` is about knowing which node this device works
 * against, which is now a real signal: a device is either pointed at a node or
 * falling back to a development default. Establishing *trust* with that node
 * is device trust, which is the separate `deviceTrusted` item.
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
    if (key === "deviceTrusted") {
      return deviceTrustedItem(inputs.workstation, inputs.device);
    }
    if (key === "localCacheComplete") {
      return localCacheItem(inputs.cache);
    }
    if (key === "lastSyncCompleted") {
      return lastSyncItem(inputs.sync);
    }
    // Unreachable: every one of the eight keys is answered above.
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
      return "The event this device cached its permissions for has ended, and the cached copy is past the six weeks it may be used after its last refresh. Reconnect to the node to continue.";
    case "no_event_context":
      return "This device holds no event context, and its cached permissions are past the six weeks they may be used after their last refresh. Reconnect to the node to continue.";
    default:
      return "This device cannot confirm the event its permissions were cached for, and the cached copy is past the six weeks it may be used after its last refresh. Reconnect to the node to continue.";
  }
}

/**
 * What this machine is, for the `device trusted` item (M18.32).
 *
 * "Is a shared workstation" is whether the machine holds a workstation identity
 * at all, which is a property of the machine rather than of the mode it is
 * running in — an Admin build pointed at a workstation identifier is still that
 * workstation, and a Kiosk with no identifier is still not one.
 *
 * Trust is null until the node has answered, so a machine mid-boot reports
 * pending rather than a failure it has not earned.
 */
export function resolveReadinessWorkstationSignal(): ReadinessWorkstationSignal {
  const context = kioskContextState.context;
  const status = kioskContextState.status;

  if (sharedWorkstationId.value === null && context === null) {
    return {
      isSharedWorkstation: false,
      trusted: null,
      workstationName: null,
      fromStoredAnswer: false,
    };
  }

  if (status === "unresolved" || status === "resolving") {
    return {
      isSharedWorkstation: true,
      trusted: null,
      workstationName: context?.workstationName ?? null,
      fromStoredAnswer: false,
    };
  }

  return {
    isSharedWorkstation: true,
    // `unknown` is the node refusing to answer for this machine, which is the
    // one honest negative: it is claiming to be a workstation this node does
    // not hold as a trusted one.
    trusted: status === "unknown" ? false : (context?.trusted ?? false),
    workstationName: context?.workstationName ?? null,
    fromStoredAnswer: status === "stored",
  };
}

/**
 * The node's answer about this session's device (AUTH-024).
 *
 * Read off the session document, which is where the node put it, and marked
 * `cached` when the document itself is the stored copy — the same distinction
 * `logged in` already draws, for the same reason: a device in a field is
 * normally working from the last answer it was given, and saying so is not the
 * same as doubting it.
 */
export function resolveReadinessDeviceSignal(): ReadinessDeviceSignal {
  const device = clientSessionState.document?.device ?? null;

  if (device === null || device === undefined) {
    return {
      named: false,
      trusted: false,
      state: "untrusted",
      label: null,
      trustedUntil: null,
      cached: false,
    };
  }

  return {
    named: true,
    trusted: device.trusted,
    state: device.trust_state,
    label: device.label,
    trustedUntil: device.trusted_until,
    cached: clientSessionState.status === "cached",
  };
}

/**
 * What this device holds of its read set, asked of the store that owns it.
 *
 * The verdict is evaluated against the moment it is asked rather than stamped at
 * refresh time, so a readiness screen left open past the end of an event window
 * stops claiming a usable cache where it stands.
 */
export function resolveReadinessCacheSignal(
  now: Date = new Date(),
): ReadinessCacheSignal {
  const verdict = offlineReadSetVerdict(now);
  const storedAt = offlineReadSetStoredAt();

  return {
    usable: verdict.access === "granted",
    held: storedAt !== null,
    reason: verdict.reason,
    storedAt,
  };
}

/**
 * Both directions of sync: what the read set last did, and what the outbox is
 * still holding.
 *
 * The outbox is counted rather than timestamped. `unsent` is the queue's own
 * word for work this device is the only copy of — queued and sending, never
 * rejected — and a rejection is counted separately because it is the one that
 * will not clear without a person.
 */
export function resolveReadinessSyncSignal(): ReadinessSyncSignal {
  const refresh = offlineReadSetRefreshStatus.value;

  // The revision is the Vue dependency for anything reading the queue, so a
  // computed built on this recomputes when a command is queued or settles.
  void commandOutboxRevision.value;

  return {
    refreshedAt: refresh.refreshedAt,
    failedAt: refresh.failedAt,
    unsentCommands: commandOutbox.unsent().length,
    rejectedCommands: commandOutbox.byStatus("rejected").length,
  };
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
    workstation: resolveReadinessWorkstationSignal(),
    device: resolveReadinessDeviceSignal(),
    cache: resolveReadinessCacheSignal(),
    sync: resolveReadinessSyncSignal(),
  });
}
