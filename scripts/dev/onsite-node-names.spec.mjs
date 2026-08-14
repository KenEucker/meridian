import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
  claimOnSiteNodeName,
  ON_SITE_NODE_HOSTNAMES,
  onSiteDnsRecords,
} from "./onsite-node-names.mjs";

/*
 * Claiming a name on the on-site convention (technical spec 8.5).
 *
 * Run by `pnpm run node:names:test`, and by the process check chain with it.
 * `node --test` rather than a vitest project, because this is one node-side
 * module beside the script that uses it and adding a test runner to reach it
 * would be the larger change.
 */

/** A network where `answers` maps a hostname to the address answering there. */
function network(answers) {
  return async (hostname) =>
    hostname in answers
      ? { answered: true, address: answers[hostname] }
      : { answered: false, address: null };
}

describe("claiming a name on the on-site convention", () => {
  it("takes the main name on a network with no node", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.10",
      probe: network({}),
    });

    assert.equal(claim.hostname, "meridian.home.arpa");
    assert.equal(claim.index, 0);
    assert.equal(claim.reclaimed, false);
    assert.deepEqual(claim.peers, []);
  });

  it("takes the next free name when another node holds the main one", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.11",
      probe: network({ "meridian.home.arpa": "192.168.1.10" }),
    });

    assert.equal(claim.hostname, "meridian2.home.arpa");
    assert.equal(claim.index, 1);
    assert.deepEqual(claim.peers, [
      { hostname: "meridian.home.arpa", address: "192.168.1.10" },
    ]);
  });

  it("skips every name another node answers", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.12",
      probe: network({
        "meridian.home.arpa": "192.168.1.10",
        "meridian2.home.arpa": "192.168.1.11",
      }),
    });

    assert.equal(claim.hostname, "meridian3.home.arpa");
    assert.equal(claim.peers.length, 2);
  });

  /*
   * The restart case. A node's own name is already answering, and reading
   * that as "taken" would walk it one name down the list on every restart
   * until it ran out of names.
   */
  it("keeps its own name when it finds itself answering", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.10",
      probe: network({ "meridian.home.arpa": "192.168.1.10" }),
    });

    assert.equal(claim.hostname, "meridian.home.arpa");
    assert.equal(claim.reclaimed, true);
    assert.deepEqual(claim.peers, []);
  });

  it("recognizes itself when the probe reached it over loopback", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.10",
      probe: network({ "meridian.home.arpa": "127.0.0.1" }),
    });

    assert.equal(claim.hostname, "meridian.home.arpa");
    assert.equal(claim.reclaimed, true);
  });

  it("reclaims a later name it already answers, without displacing the earlier one", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.11",
      probe: network({
        "meridian.home.arpa": "192.168.1.10",
        "meridian2.home.arpa": "192.168.1.11",
      }),
    });

    assert.equal(claim.hostname, "meridian2.home.arpa");
    assert.equal(claim.reclaimed, true);
  });

  /*
   * Two nodes answering to one name resolves for a phone and then
   * authenticates against whichever of them the network routed it to, so a
   * node with nowhere to go says so rather than squatting.
   */
  it("claims nothing when every name belongs to somebody else", async () => {
    const claim = await claimOnSiteNodeName({
      ownAddress: "192.168.1.99",
      probe: network({
        "meridian.home.arpa": "192.168.1.10",
        "meridian2.home.arpa": "192.168.1.11",
        "meridian3.home.arpa": "192.168.1.12",
      }),
    });

    assert.equal(claim.hostname, null);
    assert.equal(claim.peers.length, 3);
  });

  it("asks the names in the order the convention assigns them", async () => {
    const asked = [];

    await claimOnSiteNodeName({
      ownAddress: "192.168.1.10",
      probe: async (hostname) => {
        asked.push(hostname);
        return { answered: false, address: null };
      },
      hostnames: ON_SITE_NODE_HOSTNAMES,
    });

    assert.deepEqual(asked, ["meridian.home.arpa"]);
  });
});

describe("the records a node serves for the convention", () => {
  it("answers for its own name", () => {
    const records = onSiteDnsRecords({
      hostname: "meridian.home.arpa",
      ownAddress: "192.168.1.10",
    });

    assert.deepEqual(records, [
      { hostname: "meridian.home.arpa", address: "192.168.1.10" },
    ]);
  });

  it("answers for the peers it found, at the addresses they answered from", () => {
    const records = onSiteDnsRecords({
      hostname: "meridian2.home.arpa",
      ownAddress: "192.168.1.11",
      peers: [{ hostname: "meridian.home.arpa", address: "192.168.1.10" }],
    });

    assert.deepEqual(records, [
      { hostname: "meridian2.home.arpa", address: "192.168.1.11" },
      { hostname: "meridian.home.arpa", address: "192.168.1.10" },
    ]);
  });

  /*
   * A record pointing at nothing is worse than a missing one: the client
   * spends its whole timeout on it before falling through to the next name.
   */
  it("invents no record for a peer whose address is unknown", () => {
    const records = onSiteDnsRecords({
      hostname: "meridian2.home.arpa",
      ownAddress: "192.168.1.11",
      peers: [{ hostname: "meridian.home.arpa", address: null }],
    });

    assert.deepEqual(records, [
      { hostname: "meridian2.home.arpa", address: "192.168.1.11" },
    ]);
  });

  it("serves nothing of its own when it claimed no name", () => {
    const records = onSiteDnsRecords({
      hostname: null,
      ownAddress: "192.168.1.99",
      peers: [{ hostname: "meridian.home.arpa", address: "192.168.1.10" }],
    });

    assert.deepEqual(records, [
      { hostname: "meridian.home.arpa", address: "192.168.1.10" },
    ]);
  });
});
