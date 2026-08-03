import { afterEach, describe, expect, it } from "vitest";
import { effectScope } from "vue";

import {
  recordNodeAnswered,
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";
import {
  deviceConnectivityState,
  nodeConnectionStatus,
  useConnectivity,
  useNodeConnectionStatus,
  type ConnectivityScope,
} from "@/offline/useConnectivity";

afterEach(() => {
  resetNodeReachability();
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
