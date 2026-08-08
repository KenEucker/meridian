import { afterEach, describe, expect, it } from "vitest";
import { effectScope } from "vue";

import {
  recordCentralReach,
  resetCentralReachability,
} from "@/offline/centralReachability";
import {
  recordNodeAnswered,
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";
import {
  connectivityWithSyncActivity,
  CONNECTIVITY_STATE_ORDER,
  type ConnectivityState,
} from "@/offline/syncStatus";
import {
  deviceConnectivityState,
  localNodeReachable,
  nodeConnectionStatus,
  useConnectivity,
  useLocalNodeReachable,
  useNodeConnectionStatus,
  type ConnectivityScope,
} from "@/offline/useConnectivity";

afterEach(() => {
  resetNodeReachability();
  resetCentralReachability();
});

/**
 * Fake connectivity scope that records listeners so tests can drive the coarse
 * device-network signal deterministically.
 */
function createFakeScope(initialOnLine: boolean) {
  const listeners = new Map<string, Set<() => void>>();
  const scope: ConnectivityScope & { onLine: boolean } = {
    onLine: initialOnLine,
    navigator: {
      get onLine() {
        return scope.onLine;
      },
    },
    addEventListener(type, listener) {
      const set = listeners.get(type) ?? new Set();
      set.add(listener);
      listeners.set(type, set);
    },
    removeEventListener(type, listener) {
      listeners.get(type)?.delete(listener);
    },
  };

  const emit = (type: string): void => {
    for (const listener of listeners.get(type) ?? []) {
      listener();
    }
  };

  const listenerCount = (type: string): number =>
    listeners.get(type)?.size ?? 0;

  return { scope, emit, listenerCount };
}

const WITH_NETWORK: ConnectivityScope = { navigator: { onLine: true } };
const NO_NETWORK: ConnectivityScope = { navigator: { onLine: false } };

describe("deviceConnectivityState", () => {
  it("maps a device with no network to offline_usable", () => {
    expect(deviceConnectivityState(NO_NETWORK, "reachable")).toBe(
      "offline_usable",
    );
  });

  it("reports online when the device has a network and the node answered", () => {
    expect(deviceConnectivityState(WITH_NETWORK, "reachable")).toBe("online");
  });

  /*
   * The defect this composition exists for. `navigator.onLine` says the device
   * has an interface up, which a laptop on working wifi does while the node it
   * syncs with is stopped. On that signal alone the client reported "Online --
   * Central or expected sync target reachable" beside its own health probe
   * reporting "Failed to fetch", and only one of the two had asked the node.
   */
  it("does not report online when the node is not answering", () => {
    expect(deviceConnectivityState(WITH_NETWORK, "unreachable")).toBe(
      "offline_usable",
    );
  });

  it("stays silent rather than alarming while nothing is known", () => {
    // The banner is silent for `online`, and silence claims nothing. Anything
    // that has to *say* something asks `nodeConnectionStatus`, which reports
    // Unknown here rather than borrowing this answer.
    expect(deviceConnectivityState(WITH_NETWORK, "unknown")).toBe("online");
  });

  it("treats an unknown network status as having a network", () => {
    expect(deviceConnectivityState({}, "reachable")).toBe("online");
    expect(deviceConnectivityState({ navigator: {} }, "reachable")).toBe(
      "online",
    );
  });
});

/*
 * The second tier (M18.52; UI contract 11.13, 16.1).
 *
 * `online` means "Central or expected sync target reachable", and until this
 * composition existed the client said it whenever the node it was pointed at
 * answered — a claim about central that no device had checked. These are the
 * cases where the node answers and the answer is not `online`.
 */
describe("deviceConnectivityState across the two tiers", () => {
  it("reports central_unreachable, not online, for a reachable node with no central", () => {
    // The situation Meridian is deployed for: an on-site node working normally
    // at an event whose internet has gone. Saying `online` here would claim
    // central sync is fine while nothing has left the field for hours.
    expect(
      deviceConnectivityState(WITH_NETWORK, "reachable", "unreachable"),
    ).toBe("central_unreachable");
  });

  it("reports local_node_reachable when the node does not know about central", () => {
    // The node pairs with central and has no recent observation: it has just
    // started, its scheduler has stopped, or pairing is unfinished. What can
    // honestly be said is that this node is answering.
    expect(deviceConnectivityState(WITH_NETWORK, "reachable", "unknown")).toBe(
      "local_node_reachable",
    );
  });

  it("reports online when the node reached central", () => {
    expect(
      deviceConnectivityState(WITH_NETWORK, "reachable", "reachable"),
    ).toBe("online");
  });

  it("reports online when the node this device talks to is the sync target", () => {
    // Central itself, or a development node. There is no second tier to be
    // unreachable, so reaching this node is reaching everything there is.
    expect(
      deviceConnectivityState(WITH_NETWORK, "reachable", "not_applicable"),
    ).toBe("online");
  });

  it("stays silent about central when no node has reported the tier", () => {
    expect(
      deviceConnectivityState(WITH_NETWORK, "reachable", "unreported"),
    ).toBe("online");
  });

  /*
   * The local tier is decided first and completely. A device that cannot reach
   * its node is offline whatever the last thing it heard about central was —
   * that answer is now as old as the connection that carried it.
   */
  it("does not report central's state for a node it cannot reach", () => {
    expect(
      deviceConnectivityState(WITH_NETWORK, "unreachable", "unreachable"),
    ).toBe("offline_usable");
    expect(deviceConnectivityState(NO_NETWORK, "reachable", "unreachable")).toBe(
      "offline_usable",
    );
  });

  /*
   * All seven states contract 11.13 allows, and where each one comes from. Five
   * are produced by this model; two are not, and saying which is the point of
   * writing them all down.
   */
  it("accounts for every state the contract allows", () => {
    const produced = new Map<ConnectivityState, ConnectivityState>([
      [
        "online",
        deviceConnectivityState(WITH_NETWORK, "reachable", "reachable"),
      ],
      [
        "offline_usable",
        deviceConnectivityState(NO_NETWORK, "reachable", "reachable"),
      ],
      [
        "local_node_reachable",
        deviceConnectivityState(WITH_NETWORK, "reachable", "unknown"),
      ],
      [
        "central_unreachable",
        deviceConnectivityState(WITH_NETWORK, "reachable", "unreachable"),
      ],
      // The refresh states (M18.49) compose on top of connectivity rather than
      // replacing it: activity is the consequence the reader acts on.
      [
        "sync_queued",
        connectivityWithSyncActivity(
          deviceConnectivityState(WITH_NETWORK, "unreachable"),
          "queued",
        ),
      ],
      [
        "sync_failed",
        connectivityWithSyncActivity(
          deviceConnectivityState(WITH_NETWORK, "reachable", "unreachable"),
          "failed",
        ),
      ],
    ]);

    for (const [state, actual] of produced) {
      expect(actual).toBe(state);
    }

    /*
     * `sync_conflict` is the seventh and is deliberately not produced here. A
     * sync conflict is a disagreement between two nodes over a record, held in
     * the sync conflict queue and resolved in God Mode (technical spec 10.3) —
     * it is not a statement about this device's connectivity, and a device
     * inventing one from its own signals would be reporting somebody else's
     * problem as its own.
     */
    expect(
      CONNECTIVITY_STATE_ORDER.filter((state) => !produced.has(state)),
    ).toEqual(["sync_conflict"]);
  });
});

/*
 * The local tier, read as itself (M18.52).
 *
 * This is what connected-only work turns on, and the reason it is a separate
 * question: an IMS write is refused because no node is reachable, never because
 * central is unreachable.
 */
describe("localNodeReachable", () => {
  it("is true for a node that answers, whatever central is doing", () => {
    expect(localNodeReachable(WITH_NETWORK, "reachable")).toBe(true);
  });

  it("is true before the node has answered anything", () => {
    // Nothing rules the node out yet, and a request that fails will say so in
    // its own words rather than being refused ahead of time on a guess.
    expect(localNodeReachable(WITH_NETWORK, "unknown")).toBe(true);
  });

  it("is false when the node is not answering", () => {
    expect(localNodeReachable(WITH_NETWORK, "unreachable")).toBe(false);
  });

  it("is false when the device has no network at all", () => {
    expect(localNodeReachable(NO_NETWORK, "reachable")).toBe(false);
  });
});

describe("nodeConnectionStatus", () => {
  it("is unknown before the node has answered", () => {
    // Contract 16.1A: Unknown is reachable "once a connection signal exists
    // that has an indeterminate period". This is that period.
    expect(nodeConnectionStatus(WITH_NETWORK, "unknown")).toMatchObject({
      tone: "unknown",
      label: "Checking node connection",
    });
  });

  it("is connected once the node has answered", () => {
    expect(nodeConnectionStatus(WITH_NETWORK, "reachable")).toMatchObject({
      tone: "connected",
      label: "Connected and fully capable",
    });
  });

  it("is failing when the network is fine and the node says nothing", () => {
    expect(nodeConnectionStatus(WITH_NETWORK, "unreachable")).toMatchObject({
      tone: "failing",
      label: "No node reachable",
    });
  });

  /*
   * A device with no network is Degraded, not Failing, whatever it last
   * observed -- and it will usually have observed `unreachable`, because going
   * offline is what makes requests fail. The two are different situations:
   * local work continues in both, and only one of them is a surprise the user
   * cannot explain by looking at their own device.
   */
  it("is degraded when the device itself has no network", () => {
    expect(nodeConnectionStatus(NO_NETWORK, "unreachable")).toMatchObject({
      tone: "degraded",
      label: "Node connection degraded",
    });
    expect(nodeConnectionStatus(NO_NETWORK, "unknown")).toMatchObject({
      tone: "degraded",
    });
  });
});

describe("useConnectivity", () => {
  it("initializes from the current device-network signal", () => {
    const { scope } = createFakeScope(false);
    const runtime = effectScope();

    runtime.run(() => {
      const state = useConnectivity(scope);
      expect(state.value).toBe("offline_usable");
    });

    runtime.stop();
  });

  it("follows the node's answers without a network event", () => {
    // Nothing about the device changes when its node stops answering, so a
    // view-model that only listened to `online`/`offline` could never notice.
    const { scope } = createFakeScope(true);
    const runtime = effectScope();

    runtime.run(() => {
      const state = useConnectivity(scope);
      const status = useNodeConnectionStatus(scope);

      expect(status.value.tone).toBe("unknown");

      recordNodeUnreachable();
      expect(state.value).toBe("offline_usable");
      expect(status.value.tone).toBe("failing");

      recordNodeAnswered();
      expect(state.value).toBe("online");
      expect(status.value.tone).toBe("connected");
    });

    runtime.stop();
  });

  it("reacts to offline and online events", () => {
    const { scope, emit } = createFakeScope(true);
    const runtime = effectScope();

    runtime.run(() => {
      recordNodeAnswered();
      const state = useConnectivity(scope);
      expect(state.value).toBe("online");

      scope.onLine = false;
      emit("offline");
      expect(state.value).toBe("offline_usable");

      scope.onLine = true;
      emit("online");
      expect(state.value).toBe("online");
    });

    runtime.stop();
  });

  it("follows what the node reports about central without a network event", () => {
    const { scope } = createFakeScope(true);
    const runtime = effectScope();

    runtime.run(() => {
      const state = useConnectivity(scope);

      recordNodeAnswered();
      recordCentralReach("reachable");
      expect(state.value).toBe("online");

      recordCentralReach("unreachable");
      expect(state.value).toBe("central_unreachable");

      recordCentralReach("unknown");
      expect(state.value).toBe("local_node_reachable");
    });

    runtime.stop();
  });

  it("keeps the local tier steady while central comes and goes", () => {
    // The gate for connected-only work. Central going away must not take the
    // node with it: an on-site desk with no internet is still a desk with a
    // Meridian in the room.
    const { scope } = createFakeScope(true);
    const runtime = effectScope();

    runtime.run(() => {
      const reachable = useLocalNodeReachable(scope);

      recordNodeAnswered();
      recordCentralReach("unreachable");
      expect(reachable.value).toBe(true);

      recordNodeUnreachable();
      expect(reachable.value).toBe(false);
    });

    runtime.stop();
  });

  it("removes its listeners when the effect scope is disposed", () => {
    const { scope, listenerCount } = createFakeScope(true);
    const runtime = effectScope();

    runtime.run(() => {
      useConnectivity(scope);
    });
    expect(listenerCount("online")).toBe(1);
    expect(listenerCount("offline")).toBe(1);

    runtime.stop();
    expect(listenerCount("online")).toBe(0);
    expect(listenerCount("offline")).toBe(0);
  });
});
