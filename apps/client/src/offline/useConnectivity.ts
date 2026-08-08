// Device connectivity view-model (M8.6; corrected to observe the node).
//
// The shared OfflineBanner is presentational: per the component library
// specification (section 2), offline/sync state must be passed through a
// documented view-model input rather than probed ad hoc inside the component.
// This composable is that view-model.
//
// It is composed from two signals, because one of them was never enough:
//
//   - **This device's network.** `navigator.onLine === false` is a real "this
//     device has no network" signal and maps to `offline_usable`.
//   - **The node's own answers**, from `offline/nodeReachability`. A device with
//     a working network and a stopped node has no Meridian to talk to, and that
//     is the case the network signal cannot see.
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
// The richer states (`local_node_reachable`, `central_unreachable`,
// `sync_queued`, `sync_conflict`, `sync_failed`) need Meridian node-sync signals
// owned by later Alpha 1 milestones. Those milestones feed real values through
// this same view-model shape rather than having the banner invent them. The two
// connectivity tiers are M18.52's: ADR-0003 removed the device sync layer this
// comment used to defer them to, so they are owned rather than orphaned.

import { computed, onScopeDispose, ref, type Ref } from "vue";

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
 * The banner state, composed from both signals.
 *
 * `online` while nothing has been observed yet, because the banner is silent for
 * `online` and silence asserts nothing. Every other caller that needs to *say*
 * something uses {@link nodeConnectionState}, which distinguishes "not known"
 * from "answered".
 */
export function deviceConnectivityState(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
  reachability: NodeReachability = nodeReachability.value,
): ConnectivityState {
  if (!deviceNetworkAvailable(scope)) {
    return "offline_usable";
  }

  return reachability === "unreachable" ? "offline_usable" : "online";
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
    deviceConnectivityState(scope, reachability),
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
      ? deviceConnectivityState(scope, nodeReachability.value)
      : "offline_usable",
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
 * The banner signal for modules that are not components.
 *
 * `useConnectivity` disposes its listeners with the effect scope that created
 * it, which is exactly right for a component and impossible for a module: a
 * module has no scope to dispose with and no mount to key off. This one is
 * created once and lives as long as the process, which is what a question like
 * "may this client switch event right now" needs — that answer has to be
 * readable from a plain computed, not only from inside a mounted component.
 *
 * Both read the same two signals, so a component and a module cannot disagree.
 */
export const deviceConnectivity: Readonly<Ref<ConnectivityState>> = (() => {
  const scope = globalThis as ConnectivityScope;
  const available = ref(deviceNetworkAvailable(scope));

  scope.addEventListener?.("online", () => {
    available.value = deviceNetworkAvailable(scope);
  });
  scope.addEventListener?.("offline", () => {
    available.value = deviceNetworkAvailable(scope);
  });

  return computed(() =>
    available.value
      ? deviceConnectivityState(scope, nodeReachability.value)
      : "offline_usable",
  );
})();
