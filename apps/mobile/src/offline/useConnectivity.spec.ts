import { describe, expect, it } from "vitest";
import { effectScope } from "vue";

import {
  deviceConnectivityState,
  useConnectivity,
  type ConnectivityScope,
} from "@/offline/useConnectivity";

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

describe("deviceConnectivityState", () => {
  it("maps a device with no network to offline_usable", () => {
    expect(deviceConnectivityState({ navigator: { onLine: false } })).toBe(
      "offline_usable",
    );
  });

  it("reports online when the device has a network", () => {
    expect(deviceConnectivityState({ navigator: { onLine: true } })).toBe(
      "online",
    );
  });

  it("treats an unknown network status as online without nagging", () => {
    expect(deviceConnectivityState({})).toBe("online");
    expect(deviceConnectivityState({ navigator: {} })).toBe("online");
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

  it("reacts to offline and online events", () => {
    const { scope, emit } = createFakeScope(true);
    const runtime = effectScope();

    runtime.run(() => {
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
