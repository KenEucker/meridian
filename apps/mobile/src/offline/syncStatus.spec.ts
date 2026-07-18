import { describe, expect, it } from "vitest";

import {
  CONNECTIVITY_STATE_ORDER,
  describeConnectivityState,
  shouldShowOfflineBanner,
  type ConnectivityState,
} from "@/offline/syncStatus";

// Canonical labels and meanings from UI implementation contract section 16.1.
const EXPECTED: Record<ConnectivityState, { label: string; meaning: string }> = {
  online: {
    label: "Online",
    meaning: "Central or expected sync target reachable.",
  },
  offline_usable: {
    label: "Offline but usable",
    meaning: "Local work can continue.",
  },
  local_node_reachable: {
    label: "Local node reachable",
    meaning: "On-site node is reachable.",
  },
  central_unreachable: {
    label: "Central unreachable",
    meaning: "Local node may work but central sync is unavailable.",
  },
  sync_queued: {
    label: "Queued",
    meaning: "Local actions are waiting to sync.",
  },
  sync_conflict: {
    label: "Sync conflict",
    meaning: "Conflict needs handling.",
  },
  sync_failed: {
    label: "Sync failed",
    meaning: "Sync failed and may require action.",
  },
};

describe("connectivity state model", () => {
  it("lists the seven contract section 11.13 states in contract order", () => {
    expect(CONNECTIVITY_STATE_ORDER).toEqual([
      "online",
      "offline_usable",
      "local_node_reachable",
      "central_unreachable",
      "sync_queued",
      "sync_conflict",
      "sync_failed",
    ]);
  });

  it("uses the canonical section 16.1 label and meaning for each state", () => {
    for (const state of CONNECTIVITY_STATE_ORDER) {
      const descriptor = describeConnectivityState(state);
      expect(descriptor.state).toBe(state);
      expect(descriptor.label).toBe(EXPECTED[state].label);
      expect(descriptor.meaning).toBe(EXPECTED[state].meaning);
    }
  });

  it("assigns critical tone to conflict and failure states", () => {
    expect(describeConnectivityState("sync_conflict").tone).toBe("critical");
    expect(describeConnectivityState("sync_failed").tone).toBe("critical");
    expect(describeConnectivityState("central_unreachable").tone).toBe(
      "warning",
    );
  });

  it("shows the banner for every state except online", () => {
    expect(shouldShowOfflineBanner("online")).toBe(false);
    expect(describeConnectivityState("online").affectsWork).toBe(false);

    for (const state of CONNECTIVITY_STATE_ORDER) {
      if (state === "online") {
        continue;
      }
      expect(shouldShowOfflineBanner(state)).toBe(true);
      expect(describeConnectivityState(state).affectsWork).toBe(true);
    }
  });
});
