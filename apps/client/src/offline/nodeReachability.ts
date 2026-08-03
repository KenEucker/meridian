// Whether the node this device works against is answering.
//
// `navigator.onLine` — the only connectivity signal the client had until now —
// says this device has a network interface up. It says nothing about Meridian.
// A laptop on a working wifi network with the node stopped reports `true`, and
// on that basis the shell said "Connected and fully capable" and Device
// diagnostics said "Central or expected sync target reachable", directly beside
// its own health probe reporting "Failed to fetch".
//
// UI implementation contract 16.1A already accounts for this: its four-step
// scale of notice puts "no node reachable" under Failing, and no state the
// client could produce ever landed there. The same section says the Unknown step
// "is reachable only once a connection signal exists that has an indeterminate
// period" — which is this one. A device that has not yet spoken to its node
// knows nothing, and saying so is the honest answer for the moment before the
// first request comes back.
//
// What counts as an answer is the distinction `meridianCachedJson` already draws
// for its cache fallback: whether the node *spoke*, not whether it said yes. A
// 401, a 403, a 500 — all of them prove there is a Meridian at that address. A
// request that never completed is the unreachable node this exists for.
//
// This is an observation, not a probe. Nothing here polls: it records what the
// requests the client was already making happened to find. A client that is
// doing nothing learns nothing, which is why Unknown is a state rather than an
// assumption of either answer.

import { computed, ref, type Ref } from "vue";

import { nodeConnection } from "@/app/nodeConnection";

export type NodeReachability = "unknown" | "reachable" | "unreachable";

/**
 * The last observation, and the node it was made against.
 *
 * The URL is stored with it rather than the observation being cleared on a node
 * change, and that is deliberate on two counts. It ties every answer to the
 * machine that gave it, so pointing this device at a different node makes the
 * old answer Unknown rather than a claim about a machine nobody asked — the case
 * a technician correcting a node address is standing in. And it keeps this
 * module lazy: a `watch` here would evaluate `nodeConnection` at import time and
 * freeze the served node URL before the serving node had injected it.
 */
const observed = ref<{
  readonly url: string;
  readonly state: Exclude<NodeReachability, "unknown">;
} | null>(null);

/** What this device has observed about its node, from its own traffic. */
export const nodeReachability: Readonly<Ref<NodeReachability>> = computed(() => {
  const last = observed.value;

  if (last === null || last.url !== nodeConnection.value.url) {
    return "unknown";
  }

  return last.state;
});

/** The node answered. Any response counts, a refusal included. */
export function recordNodeAnswered(): void {
  observed.value = { url: nodeConnection.value.url, state: "reachable" };
}

/** The request never completed, so nothing is known to be there. */
export function recordNodeUnreachable(): void {
  observed.value = { url: nodeConnection.value.url, state: "unreachable" };
}

/** Forget what was observed. */
export function resetNodeReachability(): void {
  observed.value = null;
}
