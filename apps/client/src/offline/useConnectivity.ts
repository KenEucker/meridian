// Device connectivity view-model (M8.6; corrected to observe the node).
//
// The shared OfflineBanner is presentational: per the component library
// specification (section 2), offline/sync state must be passed through a
// documented view-model input rather than probed ad hoc inside the component.
// This composable is that view-model.
//
// It is composed from three signals, because no two of them were enough:
//
//   - **This device's network.** `navigator.onLine === false` is a real "this
//     device has no network" signal and maps to `offline_usable`.
//   - **The node's own answers**, from `offline/nodeReachability`. A device with
//     a working network and a stopped node has no Meridian to talk to, and that
//     is the case the network signal cannot see.
//   - **What the node says it can reach**, from `offline/centralReachability`.
//     The device never talks to central, so this is the one tier it cannot
//     observe and the node reports it on every response (M18.52).
//
// Until this composition existed, anything but `navigator.onLine === false`
// reported `online`, whose canonical meaning is "Central or expected sync target
// reachable" (contract 16.1). That was justified on the grounds that the banner
// stays *silent* for `online`, so the UI asserted nothing it could not confirm.
// The rationale was sound while `online` only suppressed a banner. It stopped
// being true when the shell's user button and Device diagnostics began rendering
// the label and the meaning as sentences — a client running against a stopped
// node reported "Connected and fully capable" beside a health probe reporting
// "Failed to fetch", and only one of the two had asked the node anything.
//
// So `online` now requires an answer, and the states divide by what they are
// each honest about:
//
//   - the **banner state** is one of the seven in contract 11.13, and stays
//     `online` — that is, silent — while nothing is known yet, because silence
//     claims nothing. It is not the surface that says "connected".
//   - the **node connection**, contract 16.1A's four-step scale, is nullable,
//     and null is its Unknown step. That section says Unknown "is reachable only
//     once a connection signal exists that has an indeterminate period"; this is
//     that signal, and the period is the moment before the first request
//     returns.
//
// **The two tiers** (M18.52). `online` means what contract 16.1 says it means —
// "Central or expected sync target reachable" — and until this composition
// existed the client reported it whenever the node it was pointed at answered,
// which is a claim about central that no device had checked. The two states the
// contract has always defined for the difference are produced here:
//
//   - `central_unreachable` — the node answers and reports that it cannot reach
//     central. Local work continues; sync to central does not.
//   - `local_node_reachable` — the node answers and does not know about central.
//     It has just started, its scheduler has stopped, or it has not finished
//     pairing. What can be said is that this node is reachable.
//
// A node that reports `not_applicable` — central itself, a development node — is
// the expected sync target, so reaching it *is* `online`. A node that reports
// nothing leaves the device silent, for the same reason the moment before the
// first answer does.
//
// **The local tier is what gates work, and it is read as itself.** A connected-
// only write is refused because no node is reachable, never because central is
// unreachable: an incident may be created against a reachable on-site node with
// the internet down, which is the situation Meridian is deployed for. Callers
// ask {@link localNodeReachable} rather than comparing the banner state to
// `online`, so the reason a refusal happens is the reason it is stated for.
//
// `sync_queued` and `sync_failed` are fed by the offline read set's refresh
// (M18.49) through `connectivityWithSyncActivity`. `sync_conflict` is not a
// device state and is not produced here: a sync conflict is a disagreement
// between two nodes, held in the sync conflict queue and resolved in God Mode
// (technical spec 10.3).

import { computed, onScopeDispose, ref, type Ref } from "vue";

import {
  centralReachability,
  type CentralReachability,
} from "@/offline/centralReachability";
import {
  nodeReachability,
  type NodeReachability,
} from "@/offline/nodeReachability";
import {
  describeNodeConnection,
  type ConnectivityState,
  type NodeConnectionStatus,
} from "@/offline/syncStatus";

/**
 * Minimal, injectable subset of the browser globals this composable reads, so
 * connectivity can be driven deterministically in tests. Defaults to the real
 * platform (`globalThis`).
 */
export interface ConnectivityScope {
  readonly navigator?: { readonly onLine?: boolean };
  readonly addEventListener?: (type: string, listener: () => void) => void;
  readonly removeEventListener?: (type: string, listener: () => void) => void;
}

/**
 * Whether this device has a network at all. Unknown network status is treated as
 * having one: the question this answers is "is the interface down", and only
 * `false` says so.
 */
export function deviceNetworkAvailable(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
): boolean {
  return scope.navigator?.onLine !== false;
}

/**
 * Whether there is a Meridian this device can work against right now.
 *
 * The local tier, on its own, because it is what connected-only work turns on
 * and it must not be inferred from a banner state that also carries central's
 * problems. An unknown node counts as reachable: nothing rules it out, and a
 * request that fails will say so in its own words rather than being refused
 * ahead of time on a guess.
 */
export function localNodeReachable(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
  reachability: NodeReachability = nodeReachability.value,
): boolean {
  return deviceNetworkAvailable(scope) && reachability !== "unreachable";
}

/**
 * The banner state, composed from all three signals.
 *
 * `online` while nothing has been observed yet, because the banner is silent for
 * `online` and silence asserts nothing. Every other caller that needs to *say*
 * something uses {@link nodeConnectionStatus}, which distinguishes "not known"
 * from "answered".
 *
 * The node tier is decided first and completely: a device that cannot reach its
 * node is `offline_usable` whatever the last thing it heard about central was,
 * because that answer is now as old as the connection that carried it.
 */
export function deviceConnectivityState(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
  reachability: NodeReachability = nodeReachability.value,
  central: CentralReachability = centralReachability.value,
): ConnectivityState {
  if (!localNodeReachable(scope, reachability)) {
    return "offline_usable";
  }

  if (reachability === "unknown") {
    return "online";
  }

  if (central === "unreachable") {
    return "central_unreachable";
  }

  return central === "unknown" ? "local_node_reachable" : "online";
}

/**
 * The node connection on contract 16.1A's scale.
 *
 * Unknown — a null state — only where it is true: this device has a network, so
 * nothing rules the node out, and it has not yet heard from the node, so nothing
 * confirms it. A device with no network at all is not unknown; it is offline,
 * and knowing that needs no answer from anybody.
 */
export function nodeConnectionStatus(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
  reachability: NodeReachability = nodeReachability.value,
): NodeConnectionStatus {
  if (!deviceNetworkAvailable(scope)) {
    return describeNodeConnection("offline_usable", false);
  }

  if (reachability === "unknown") {
    return describeNodeConnection(null, false);
  }

  return describeNodeConnection(
    deviceConnectivityState(scope, reachability, centralReachability.value),
    reachability === "unreachable",
  );
}

/**
 * Reactively track the device network, updating on the platform
 * `online`/`offline` events and cleaning up its listeners when the owning effect
 * scope is disposed.
 */
function trackDeviceNetwork(scope: ConnectivityScope): Ref<boolean> {
  const available = ref(deviceNetworkAvailable(scope));

  const update = (): void => {
    available.value = deviceNetworkAvailable(scope);
  };

  if (scope.addEventListener) {
    scope.addEventListener("online", update);
    scope.addEventListener("offline", update);

    onScopeDispose(() => {
      scope.removeEventListener?.("online", update);
      scope.removeEventListener?.("offline", update);
    });
  }

  return available;
}

/** Reactive banner state. */
export function useConnectivity(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
): Readonly<Ref<ConnectivityState>> {
  const available = trackDeviceNetwork(scope);

  return computed(() =>
    available.value
      ? deviceConnectivityState(
          scope,
          nodeReachability.value,
          centralReachability.value,
        )
      : "offline_usable",
  );
}

/**
 * Reactive local tier: whether a Meridian node is reachable from this device.
 *
 * What a surface asks before offering work that cannot be held on the device. It
 * is deliberately not "is the banner silent": a Logistics desk on an on-site
 * node with the internet down is a desk that can still create incidents, and a
 * gate written against `online` would have refused them the moment central went
 * away (UI contract 16.1, 16.2).
 */
export function useLocalNodeReachable(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
): Readonly<Ref<boolean>> {
  const available = trackDeviceNetwork(scope);

  return computed(
    () => available.value && localNodeReachable(scope, nodeReachability.value),
  );
}

/**
 * Reactive node connection on contract 16.1A's scale, for anything that reports
 * it in words.
 *
 * `available` is read rather than passed so the computed re-evaluates on the
 * platform's `online`/`offline` events; `nodeConnectionStatus` reads the same
 * value again from the scope.
 */
export function useNodeConnectionStatus(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
): Readonly<Ref<NodeConnectionStatus>> {
  const available = trackDeviceNetwork(scope);

  return computed(() => {
    void available.value;

    return nodeConnectionStatus(scope, nodeReachability.value);
  });
}

/**
 * The device-network signal for modules that are not components.
 *
 * `trackDeviceNetwork` disposes its listeners with the effect scope that created
 * it, which is exactly right for a component and impossible for a module: a
 * module has no scope to dispose with and no mount to key off. This one is
 * created once and lives as long as the process, which is what a question like
 * "may this client switch event right now" needs — that answer has to be
 * readable from a plain computed, not only from inside a mounted component.
 *
 * One listener pair for both module signals below, so the banner state and the
 * local tier cannot see different networks.
 */
const platformNetwork: Readonly<Ref<boolean>> = (() => {
  const scope = globalThis as ConnectivityScope;
  const available = ref(deviceNetworkAvailable(scope));

  scope.addEventListener?.("online", () => {
    available.value = deviceNetworkAvailable(scope);
  });
  scope.addEventListener?.("offline", () => {
    available.value = deviceNetworkAvailable(scope);
  });

  return available;
})();

/**
 * The banner signal for modules that are not components.
 *
 * The same three signals `useConnectivity` reads, so a component and a module
 * cannot disagree about what this device's connectivity is.
 */
export const deviceConnectivity: Readonly<Ref<ConnectivityState>> = computed(
  () =>
    platformNetwork.value
      ? deviceConnectivityState(
          globalThis as ConnectivityScope,
          nodeReachability.value,
          centralReachability.value,
        )
      : "offline_usable",
);

/**
 * The local tier for modules that are not components.
 *
 * The signal every connected-only path reads: the command outbox before it
 * refuses a write, the context switcher before it offers one, the read-set
 * refresh before it asks again. Each of those is asking whether there is a node
 * to talk to, and none of them is asking anything about central.
 */
export const deviceLocalNodeReachable: Readonly<Ref<boolean>> = computed(
  () =>
    platformNetwork.value &&
    localNodeReachable(globalThis as ConnectivityScope, nodeReachability.value),
);
