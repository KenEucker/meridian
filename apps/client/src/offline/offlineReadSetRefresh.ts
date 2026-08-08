// When the device fetches its offline read set, and what it says while it is
// trying (M18.49; ADR-0003; CLIENT-001; technical spec 9.3, 11A.4; UI contract
// 11.13, 16).
//
// **Proactive, not opportunistic.** The read cache this replaces stored what the
// user happened to open while they had a signal, which meant the device's
// offline capability was a record of its owner's browsing rather than of their
// authorization. This fetches the whole composed set at the three moments the
// answer can newly be wrong:
//
//  1. **Sign-in.** The first moment there is a caller to compose a set for. A
//     device that signs in at the gate and walks into a field with no coverage
//     holds its shifts, its documents, and its department because of this
//     trigger and not because it visited the right screens first.
//  2. **Regaining connectivity.** The set is composed from live grants, so the
//     first thing a reconnecting device should do is find out what it may still
//     hold — this is the read-side half of the reduction 11A.4 already requires
//     of permissions on reconnect.
//  3. **Context switch.** The set for the event being left is dropped on the
//     switch (M18.48, CLIENT-014) and there is no version of it that belongs to
//     the event being entered, so a device that switched and did not refetch
//     would hold nothing at all until something asked.
//
// Triggers one and three are one watcher on the resolved context, because they
// are the same event seen twice: sign-in moves the context from nothing to
// something and a switch moves it from something to something else. Naming them
// apart in {@link OfflineReadSetRefreshTrigger} is for the reporting, not for
// the rule.
//
// **One pull at a time, and it reads the context when it runs.** Refreshes are
// serialized through a promise chain, and the context is resolved at execution
// rather than when the refresh was asked for. Without that, a switch during an
// in-flight pull would land the previous event's set on a device that had just
// dropped it — the store's own generation stamping cannot see that, because from
// its side a late arrival and a fresh answer are the same call. A refresh also
// waits for the durable copy to be read before asking, because the sign-in
// trigger fires at boot against a cached session — exactly while that read is in
// flight — and a refresh that overtook it would hold no version, send no
// `If-None-Match`, and transfer the whole set on every start.
//
// **What it says while trying.** Refresh state goes through the OfflineBanner
// view-model that already exists rather than through a second indicator
// (contract 11.13, 16.2). Two of the seven states M8.6 defined and left unfed
// take their values here: a refresh that could not reach the node while the
// device has nothing usable to work from is `sync_queued`, and a refresh the
// node answered and refused or garbled is `sync_failed`. A device that is merely
// offline and holding a usable set stays on its connectivity state and the
// banner stays quiet, because nothing about that situation needs acting on.

import { computed, ref, watch, type Ref } from "vue";

import {
  clearOfflineReadSet,
  offlineReadSetHydrated,
  offlineReadSetRevision,
  offlineReadSetUsable,
  pullOfflineReadSet,
  type OfflineReadSetPullOutcome,
} from "@/offline/offlineReadSetRuntime";
import type { OfflineReadSetContext } from "@/offline/offlineReadSetStorage";
import {
  connectivityWithSyncActivity,
  type ConnectivityState,
  type SyncActivity,
} from "@/offline/syncStatus";
import { deviceConnectivity } from "@/offline/useConnectivity";
import {
  clientSessionState,
  sessionAccessGranted,
} from "@/session/clientSession";

/** Why a refresh was asked for. Reported; never changes what is fetched. */
export type OfflineReadSetRefreshTrigger =
  | "sign_in"
  | "reconnect"
  | "context_switch"
  /** Asked for directly, by a test or by a surface offering a retry. */
  | "requested";

/** What a refresh did, including deciding there was nothing to do. */
export type OfflineReadSetRefreshOutcome =
  | OfflineReadSetPullOutcome
  /** No session, no access, or a pull already covering this context. */
  | "skipped";

export interface OfflineReadSetRefreshStatus {
  readonly trigger: OfflineReadSetRefreshTrigger | null;
  readonly outcome: OfflineReadSetRefreshOutcome | null;
  /** Device time of the last attempt that came back with the node's answer. */
  readonly refreshedAt: string | null;
  /** Device time of the last attempt that did not. */
  readonly failedAt: string | null;
  /** The node's own words, when it refused with any. */
  readonly detail: string | null;
}

const IDLE: OfflineReadSetRefreshStatus = Object.freeze({
  trigger: null,
  outcome: null,
  refreshedAt: null,
  failedAt: null,
  detail: null,
});

const status = ref<OfflineReadSetRefreshStatus>(IDLE);

/** What the last refresh did, for the banner and for the specs. */
export const offlineReadSetRefreshStatus: Readonly<
  Ref<OfflineReadSetRefreshStatus>
> = status;

/**
 * The context a refresh composes against, or null when there is nothing to
 * compose for.
 *
 * Read off the session document rather than computed here, in the same way every
 * other consumer reads it: the node resolves the context and the client reports
 * it (CLIENT-011). A session whose access is refused resolves to null, because a
 * client that will not act on the permissions it holds has no business asking
 * for a set composed from them.
 */
function currentContext(): OfflineReadSetContext | null {
  if (!sessionAccessGranted.value) {
    return null;
  }

  const context = clientSessionState.document?.context ?? null;

  if (context === null) {
    return null;
  }

  return {
    organizationId: context.organization_id,
    eventId: context.event_id,
  };
}

/** The identity a context change is detected by. */
const contextKey = computed<string | null>(() => {
  const context = currentContext();

  return context === null
    ? null
    : `${context.organizationId ?? ""}:${context.eventId ?? ""}`;
});

/** The chain that keeps one pull in flight at a time. */
let queue: Promise<unknown> = Promise.resolve();

function record(
  trigger: OfflineReadSetRefreshTrigger,
  outcome: OfflineReadSetRefreshOutcome,
  detail: string | null,
  now: Date,
): void {
  const previous = status.value;
  const reached = outcome === "refreshed" || outcome === "unchanged";

  status.value = {
    trigger,
    outcome,
    refreshedAt: reached ? now.toISOString() : previous.refreshedAt,
    failedAt:
      outcome === "skipped" || reached ? previous.failedAt : now.toISOString(),
    detail,
  };
}

async function execute(
  trigger: OfflineReadSetRefreshTrigger,
  now: Date,
): Promise<OfflineReadSetRefreshOutcome> {
  /*
   * Find out what this device already holds before asking for more of it. The
   * sign-in trigger fires at boot against a cached session, which is exactly
   * when the durable copy is still being read, and a refresh that overtook it
   * would send no `If-None-Match` and transfer the whole set every start.
   */
  await offlineReadSetHydrated();

  /*
   * Resolved here rather than when the refresh was asked for. A refresh that
   * waited behind another one belongs to wherever the client is standing now,
   * not to wherever it stood when the trigger fired — which on a context switch
   * are two different events.
   */
  const context = currentContext();

  if (context === null) {
    record(trigger, "skipped", null, now);

    return "skipped";
  }

  const composedFor = contextKey.value;
  const result = await pullOfflineReadSet(context, { now });

  /*
   * The session ended, or moved, while the node was answering. The set that
   * just landed was composed for a context this client has left, and the drop
   * that ran on the way out could not have known about it — from the store's
   * side a late arrival and a fresh answer are the same call (CLIENT-014;
   * technical spec 13.3). Whatever the client is in now gets its own refresh
   * from the context watcher; what must not happen is the departed context's
   * rows sitting there in the meantime.
   */
  if (result.outcome === "refreshed" && contextKey.value !== composedFor) {
    clearOfflineReadSet();
  }

  record(trigger, result.outcome, result.detail, now);

  return result.outcome;
}

/**
 * Fetch the set for the context this client is in.
 *
 * Serialized rather than concurrent, because two pulls in flight against
 * different contexts race to install into one store and the loser of that race
 * is whichever answered first, not whichever is current.
 */
export function refreshOfflineReadSet(
  trigger: OfflineReadSetRefreshTrigger = "requested",
  options: { readonly now?: Date } = {},
): Promise<OfflineReadSetRefreshOutcome> {
  const run = queue.then(() => execute(trigger, options.now ?? new Date()));

  queue = run.then(
    () => undefined,
    () => undefined,
  );

  return run;
}

/**
 * Refresh on regaining connectivity (technical spec 11A.4).
 *
 * On the transition only. A device that is already online has nothing to regain,
 * and asking on every connectivity event would put a request on the wire each
 * time a phone changes access point. `undefined` is the first evaluation of the
 * watcher rather than a transition, and boot is covered by the sign-in trigger.
 */
export function refreshOfflineReadSetOnReconnect(
  connectivity: ConnectivityState,
  previous: ConnectivityState | undefined,
): Promise<OfflineReadSetRefreshOutcome> {
  if (
    connectivity !== "online" ||
    previous === undefined ||
    previous === "online"
  ) {
    return Promise.resolve("skipped");
  }

  return refreshOfflineReadSet("reconnect");
}

/**
 * Watch the two things that make the set worth fetching, for the life of the
 * application.
 *
 * Installed by `main.ts` rather than by the shell, because this is application
 * behavior and not something a screen turns on: a device whose set went stale
 * while nobody was looking at a shell still needs it fetched. Returns its own
 * teardown, which only the specs use — an application that stopped refreshing
 * its read set would be an application that quietly stopped being usable
 * offline.
 */
export function installOfflineReadSetRefreshTriggers(): () => void {
  const stopContext = watch(
    contextKey,
    (key, previous) => {
      if (key === null) {
        // Signed out, or access refused. The set is dropped by the reset
        // registry; there is nothing to fetch and nobody to fetch it for.
        return;
      }

      const fresh = previous === null || previous === undefined;

      void refreshOfflineReadSet(fresh ? "sign_in" : "context_switch");
    },
    { immediate: true },
  );

  const stopConnectivity = watch(deviceConnectivity, (state, previous) => {
    void refreshOfflineReadSetOnReconnect(state, previous);
  });

  return () => {
    stopContext();
    stopConnectivity();
  };
}

/**
 * What the banner should say about the read set, on contract 11.13's terms.
 *
 * `failed` is the node having answered and the answer having been unusable — it
 * refused the context, or it sent something that is not a read set. Neither
 * clears up on its own and both are conditions somebody acts on.
 *
 * `queued` is narrower than "a refresh did not happen", deliberately. A device
 * that could not reach its node but holds a set it may serve is working exactly
 * as designed, and saying "Queued" at it would be sync noise over routine field
 * work (contract 16.2). The state is for the device that has nothing to work
 * from and is waiting on connectivity to get it.
 */
export const offlineReadSetSyncActivity = computed<SyncActivity>(() => {
  const current = status.value;

  if (current.outcome === "refused" || current.outcome === "unusable") {
    return "failed";
  }

  // Reactive on the set as well as on the refresh, so a set that arrives — or
  // is dropped — moves the banner without waiting for the next attempt.
  void offlineReadSetRevision.value;

  if (offlineReadSetUsable()) {
    return "settled";
  }

  return current.outcome === "unreachable" ? "queued" : "settled";
});

/**
 * The banner state for this device, connectivity and read-set refresh together.
 *
 * One view-model, as contract 11.13 has it, rather than a second indicator
 * beside the first.
 */
export function offlineBannerState(
  connectivity: ConnectivityState,
): ConnectivityState {
  return connectivityWithSyncActivity(
    connectivity,
    offlineReadSetSyncActivity.value,
  );
}

/** Reset refresh state between tests. */
export function resetOfflineReadSetRefresh(): void {
  status.value = IDLE;
  queue = Promise.resolve();
}
