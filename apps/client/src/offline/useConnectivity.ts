// Device connectivity view-model (M8.6).
//
// The shared OfflineBanner is presentational: per the component library
// specification (section 2), offline/sync state must be passed through a
// documented view-model input rather than probed ad hoc inside the component.
// This composable is that view-model for the coarse device-network signal that
// the client can honestly observe today.
//
// Scope of the honest signal:
//   - `navigator.onLine === false` is a real "this device has no network"
//     signal, so it maps to `offline_usable` (local work can continue).
//   - When the device reports a network, this composable reports `online`.
//     The banner stays silent for `online` (see syncStatus.ts), so the UI never
//     asserts that the central/sync target is actually reachable when it cannot
//     confirm it (contract section 16.2: do not imply current central truth).
//
// The richer states (`local_node_reachable`, `central_unreachable`,
// `sync_queued`, `sync_conflict`, `sync_failed`) require PowerSync client sync
// state and Meridian node-sync signals owned by later Alpha 1 milestones. Those
// milestones feed real values into the OfflineBanner through this same
// view-model shape rather than having the banner invent them.

import { onScopeDispose, readonly, ref, type Ref } from "vue";

import type { ConnectivityState } from "@/offline/syncStatus";

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
 * Map the device-network signal to a connectivity state. Only the coarse
 * offline vs online distinction is derived here; unknown network status is
 * treated as `online` so the banner stays silent and does not nag.
 */
export function deviceConnectivityState(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
): ConnectivityState {
  return scope.navigator?.onLine === false ? "offline_usable" : "online";
}

/**
 * Reactively track coarse device connectivity. Returns a read-only ref that
 * updates on the platform `online`/`offline` events and cleans up its listeners
 * when the owning effect scope is disposed.
 */
export function useConnectivity(
  scope: ConnectivityScope = globalThis as ConnectivityScope,
): Readonly<Ref<ConnectivityState>> {
  const state = ref<ConnectivityState>(deviceConnectivityState(scope));

  const update = (): void => {
    state.value = deviceConnectivityState(scope);
  };

  if (scope.addEventListener) {
    scope.addEventListener("online", update);
    scope.addEventListener("offline", update);

    onScopeDispose(() => {
      scope.removeEventListener?.("online", update);
      scope.removeEventListener?.("offline", update);
    });
  }

  return readonly(state);
}

/**
 * The same signal for modules that are not components.
 *
 * `useConnectivity` disposes its listeners with the effect scope that created
 * it, which is exactly right for a component and impossible for a module: a
 * module has no scope to dispose with and no mount to key off. This one is
 * created once and lives as long as the process, which is what a question like
 * "may this client switch event right now" needs — that answer has to be
 * readable from a plain computed, not only from inside a mounted component.
 *
 * Both read `deviceConnectivityState`, so a component and a module cannot
 * disagree about whether the device has a network.
 */
export const deviceConnectivity: Readonly<Ref<ConnectivityState>> = (() => {
  const scope = globalThis as ConnectivityScope;
  const state = ref<ConnectivityState>(deviceConnectivityState(scope));

  scope.addEventListener?.("online", () => {
    state.value = deviceConnectivityState(scope);
  });
  scope.addEventListener?.("offline", () => {
    state.value = deviceConnectivityState(scope);
  });

  return readonly(state);
})();
