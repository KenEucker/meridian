import { afterEach, describe, expect, it } from "vitest";

import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";
import {
  centralReachability,
  recordCentralReach,
  resetCentralReachability,
} from "@/offline/centralReachability";

/*
 * What the node says it can reach (M18.52; technical spec 9.6; UI contract
 * 11.13, 16.1).
 *
 * The device cannot observe this tier — it never talks to central — so every
 * value here arrives from a node, and the tests are about what this module does
 * with an answer rather than about how one is produced.
 */

afterEach(() => {
  resetCentralReachability();
  clearNodeUrl();
  window.localStorage.clear();
});

describe("centralReachability", () => {
  it("reports nothing until a node has said something", () => {
    expect(centralReachability.value).toBe("unreported");
  });

  it("records each state a node may report", () => {
    for (const state of [
      "reachable",
      "unreachable",
      "unknown",
      "not_applicable",
    ] as const) {
      recordCentralReach(state);
      expect(centralReachability.value).toBe(state);
    }
  });

  /*
   * The two ways of not knowing are different claims. `unreported` is this
   * device having been told nothing; `unknown` is the node saying it does not
   * know, which is a fact about a node that pairs with central and has not heard
   * from it. The connectivity model says different things about them.
   */
  it("keeps a node's own unknown apart from having been told nothing", () => {
    recordCentralReach("unknown");
    expect(centralReachability.value).toBe("unknown");

    resetCentralReachability();
    expect(centralReachability.value).toBe("unreported");
  });

  it("stops claiming anything when a response carries no report", () => {
    recordCentralReach("unreachable");
    recordCentralReach(null);

    expect(centralReachability.value).toBe("unreported");
  });

  it("ignores a value it does not recognize", () => {
    recordCentralReach("probably");

    expect(centralReachability.value).toBe("unreported");
  });

  /*
   * The answer belongs to the node that gave it. Pointing this device at a
   * different node is the case a technician correcting a node address is
   * standing in, and the previous node's answer is not a claim about the new
   * one.
   */
  it("forgets what one node said when the device is pointed at another", () => {
    recordCentralReach("unreachable");
    expect(centralReachability.value).toBe("unreachable");

    setNodeUrl("https://onsite.example.org");
    expect(centralReachability.value).toBe("unreported");
  });
});
