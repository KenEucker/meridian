/**
 * Claiming a name on the on-site convention (technical spec 8.5).
 *
 * `meridian.home.arpa` is the main on-site node and additional nodes take
 * `meridian2`, `meridian3` in order, so a node joining a network works out
 * which one it is rather than being told: it asks each name in turn and takes
 * the first that answers nothing, or that already answers as itself.
 *
 * The rule that matters is the one about not displacing anybody. A name
 * another node answers is that node's name, and a second node that claimed it
 * would leave every phone on the network resolving one address and
 * authenticating against another. Restarting a node is the case that makes
 * this subtle: its own name is already answering, and treating that as "taken"
 * would walk it down the list on every restart until it ran out of names.
 *
 * Pure, and separated from the script that runs it, because the interesting
 * part is the decision rather than the probing. `probe` reports what answered
 * at a name; everything below is arithmetic on those answers.
 */

/** The convention names, in the order the convention assigns them. */
export const ON_SITE_NODE_HOSTNAMES = [
  "meridian.home.arpa",
  "meridian2.home.arpa",
  "meridian3.home.arpa",
];

/**
 * What a probe found at one name.
 *
 * @typedef {object} NodeProbeResult
 * @property {boolean} answered Whether a Meridian node answered at all.
 * @property {string | null} address The address it answered from, when known.
 */

/**
 * Work out which convention name belongs to this node.
 *
 * @param {object} options
 * @param {string} options.ownAddress This machine's address on this network.
 * @param {(hostname: string) => Promise<NodeProbeResult>} options.probe
 * @param {readonly string[]} [options.hostnames]
 * @returns {Promise<{
 *   hostname: string | null,
 *   index: number,
 *   reclaimed: boolean,
 *   peers: Array<{ hostname: string, address: string | null }>,
 * }>}
 */
export async function claimOnSiteNodeName({
  ownAddress,
  probe,
  hostnames = ON_SITE_NODE_HOSTNAMES,
}) {
  const peers = [];

  for (const [index, hostname] of hostnames.entries()) {
    const result = await probe(hostname);

    if (!result.answered) {
      return { hostname, index, reclaimed: false, peers };
    }

    /*
     * Already mine. A node that restarts finds its own name answering, and
     * the honest reading of that is "this is where I was", not "somebody is
     * here" — otherwise every restart moves the node one name down the list.
     */
    if (isSameAddress(result.address, ownAddress)) {
      return { hostname, index, reclaimed: true, peers };
    }

    peers.push({ hostname, address: result.address ?? null });
  }

  /*
   * Every name answered, and none of them was this node. Refusing to claim
   * one is the whole point: the alternative is two nodes answering to the
   * same name, which resolves for a phone and then authenticates against
   * whichever of them the network happened to route it to.
   */
  return { hostname: null, index: -1, reclaimed: false, peers };
}

/**
 * The name-to-address records this node should serve, when it serves DNS.
 *
 * Its own name, plus the peers it actually found at the addresses they
 * answered from. A node does not invent records for names nothing answered:
 * an address that resolves to nothing is worse than a name that does not
 * resolve, because the client spends its timeout before falling through.
 */
export function onSiteDnsRecords({ hostname, ownAddress, peers = [] }) {
  const records = [];

  if (hostname !== null) {
    records.push({ hostname, address: ownAddress });
  }

  for (const peer of peers) {
    if (peer.address !== null && peer.hostname !== hostname) {
      records.push({ hostname: peer.hostname, address: peer.address });
    }
  }

  return records;
}

/**
 * Whether two addresses name the same machine.
 *
 * A probe reports the address it reached, which for a node answering its own
 * name over the loopback path may be `127.0.0.1` while the network knows it
 * as its LAN address. Both mean "me".
 */
function isSameAddress(probed, ownAddress) {
  if (probed === null || probed === undefined) {
    return false;
  }

  return probed === ownAddress || LOOPBACK_ADDRESSES.has(probed);
}

const LOOPBACK_ADDRESSES = new Set(["127.0.0.1", "::1", "localhost"]);
