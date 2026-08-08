// What the node this device talks to says about *its* connectivity (M18.52;
// technical spec 9.6; UI implementation contract 11.13, 16.1).
//
// `nodeReachability` answers the question this device can answer for itself:
// does the node at the other end of my requests reply. It is the whole of the
// local tier and none of the second one. A device working happily against an
// on-site node whose internet has gone has a node that answers everything and a
// central that has not been heard from in an hour, and nothing on the device can
// tell the difference — it never talks to central, and it never should.
//
// So the node says. `CentralReachability::HEADER` rides every API response
// (`ReportCentralReach`), and this module is the client's record of the last
// thing it said. The device learns both tiers from the same traffic at the same
// moment, which is what keeps them from disagreeing or going stale apart.
//
// The two ways of not knowing are separate states, because they call for
// different things to be said:
//
//   `unreported` — nothing has told this device anything. Boot, a node that does
//                  not report the tier, a browser that cannot read the header
//                  cross-origin. The device stays silent about central, exactly
//                  as it does before the first answer to anything.
//   `unknown`    — the node reports that it does not know: it pairs with central
//                  and has no recent observation. That is a claim, and it earns
//                  `local_node_reachable` — this node is answering, and nothing
//                  is known beyond it.
//
// Observed rather than probed, like everything else in this model. Nothing here
// polls; it records what the requests the client was already making came back
// with.

import { computed, ref, type Ref } from "vue";

import { nodeConnection } from "@/app/nodeConnection";

/** The header the node reports its own reach to central on. */
export const CENTRAL_REACH_HEADER = "Meridian-Central-Reach";

export type CentralReachability =
  /** Nothing has said anything about central to this device. */
  | "unreported"
  /** This node is the expected sync target; there is no central beyond it. */
  | "not_applicable"
  /** The node's last exchange reached central. */
  | "reachable"
  /** The node's last exchange did not. */
  | "unreachable"
  /** The node pairs with central and has no recent observation. */
  | "unknown";

/** The values a node may report. Anything else is not an answer. */
const REPORTED = new Set<string>([
  "not_applicable",
  "reachable",
  "unreachable",
  "unknown",
]);

type ReportedCentralReachability = Exclude<CentralReachability, "unreported">;

/**
 * The last thing a node said, and which node said it.
 *
 * Stored with the node URL for the reason `nodeReachability` stores it: pointing
 * this device at a different node makes the previous answer a claim about a
 * machine nobody asked. A technician correcting a node address is standing in
 * exactly that case.
 */
const observed = ref<{
  readonly url: string;
  readonly state: ReportedCentralReachability;
} | null>(null);

/** What this device has been told about its node's reach to central. */
export const centralReachability: Readonly<Ref<CentralReachability>> = computed(
  () => {
    const last = observed.value;

    if (last === null || last.url !== nodeConnection.value.url) {
      return "unreported";
    }

    return last.state;
  },
);

/**
 * Record what a response said, or that it said nothing.
 *
 * A response with no header — or with a value this client does not recognize —
 * clears the observation rather than leaving the previous one standing. A device
 * must not keep repeating a claim its node has stopped making, and silence about
 * central is a state this model has (`unreported`) rather than a gap it has to
 * paper over.
 */
export function recordCentralReach(header: string | null | undefined): void {
  const reported = (header ?? "").trim();

  if (!REPORTED.has(reported)) {
    observed.value = null;

    return;
  }

  observed.value = {
    url: nodeConnection.value.url,
    state: reported as ReportedCentralReachability,
  };
}

/** Forget what was reported. */
export function resetCentralReachability(): void {
  observed.value = null;
}
