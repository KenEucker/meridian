// Which node this device is talking to (M18.61; AUTH-034; technical spec
// 13.4).
//
// The one question `staff.workstation-code` cannot answer from anything it
// already holds: a workstation QR names the node that issued the request, and
// refusing a foreign-node QR with both identities named requires knowing this
// device's own. The health endpoint carries it, because the health endpoint is
// the one read that answers before anything else about a node is known.
//
// Cached for the session: which node a device is pointed at changes when its
// configuration does, not between scans.

import { meridianJson } from "@/api/meridianApi";

export interface OwnNodeIdentity {
  readonly id: string | null;
  readonly name: string | null;
}

let cached: OwnNodeIdentity | null = null;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

/**
 * This device's node, or null when the node cannot be reached. An unreachable
 * node is not an identity — the caller decides what a missing answer means for
 * the check it was making.
 */
export async function resolveOwnNodeIdentity(): Promise<OwnNodeIdentity | null> {
  if (cached !== null) {
    return cached;
  }

  try {
    const payload = await meridianJson<unknown>("/api/health");

    if (!isRecord(payload)) {
      return null;
    }

    cached = {
      id: asString(payload.node_id),
      name: asString(payload.node_name),
    };

    return cached;
  } catch {
    return null;
  }
}

/** Reset the cache between tests, and after reconfiguring the node. */
export function resetOwnNodeIdentity(): void {
  cached = null;
}
