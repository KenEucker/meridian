import { afterEach, describe, expect, it } from "vitest";

import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";
import {
  buildReadinessChecklist,
  resolveReadinessChecklist,
  summarizeReadiness,
  type ReadinessChecklistInputs,
  type ReadinessItemKey,
} from "@/readiness/checklist";
import type { DeviceSigningReadiness } from "@/readiness/deviceSigning";
import type { LocalEncryptionReadiness } from "@/readiness/localEncryption";

afterEach(() => {
  clearNodeUrl();
  window.localStorage.clear();
});

/** No node has been configured and none served this client. */
function unknownNode() {
  return {
    url: "http://127.0.0.1:8000",
    source: "default" as const,
    servedUrl: null,
    overridesServingNode: false,
  };
}

/** This device has been pointed at a node. */
function configuredNode() {
  return {
    url: "https://onsite.example.org",
    source: "configured" as const,
    servedUrl: null,
    overridesServingNode: false,
  };
}

// Technical spec section 14 lists the readiness items in this exact order.
const SECTION_14_ORDER: ReadinessItemKey[] = [
  "loggedIn",
  "deviceTrusted",
  "eventSelected",
  "localCacheComplete",
  "encryptionActive",
  "lastSyncCompleted",
  "trustedServerKnown",
  "deviceSigningAvailable",
];

function availableEncryption(): LocalEncryptionReadiness {
  return {
    capability: "local-encryption",
    available: true,
    status: "available",
    missing: [],
    reason: null,
  };
}

function unavailableEncryption(): LocalEncryptionReadiness {
  return {
    capability: "local-encryption",
    available: false,
    status: "unavailable",
    missing: ["secureContext"],
    reason: "Local encryption is unavailable: the app is not running in a secure context.",
  };
}

function availableSigning(): DeviceSigningReadiness {
  return {
    capability: "device-signing",
    available: true,
    status: "available",
    missing: [],
    reason: null,
  };
}

function unavailableSigning(): DeviceSigningReadiness {
  return {
    capability: "device-signing",
    available: false,
    status: "unavailable",
    missing: ["persistentKeyStore"],
    reason: "Device signing is unavailable: secure local signing-key storage is unavailable.",
  };
}

function inputs(
  overrides: Partial<ReadinessChecklistInputs> = {},
): ReadinessChecklistInputs {
  return {
    localEncryption: availableEncryption(),
    deviceSigning: availableSigning(),
    node: configuredNode(),
    ...overrides,
  };
}

function capableScope() {
  return {
    isSecureContext: true,
    crypto: {
      subtle: {
        generateKey: () => undefined,
        encrypt: () => undefined,
        decrypt: () => undefined,
        sign: () => undefined,
        verify: () => undefined,
      },
    },
    indexedDB: {},
  };
}

describe("buildReadinessChecklist", () => {
  it("lists the eight section 14 items in spec order with spec wording", () => {
    const items = buildReadinessChecklist(inputs());

    expect(items.map((item) => item.key)).toEqual(SECTION_14_ORDER);
    expect(items.map((item) => item.label)).toEqual([
      "Logged in",
      "Device trusted",
      "Event selected",
      "Local cache complete",
      "Encryption active",
      "Last sync completed",
      "Trusted server known",
      "Device signing available",
    ]);
  });

  it("maps available encryption and signing signals to ready", () => {
    const items = buildReadinessChecklist(inputs());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.encryptionActive).toEqual({
      key: "encryptionActive",
      label: "Encryption active",
      status: "ready",
      detail: null,
    });
    expect(byKey.deviceSigningAvailable).toEqual({
      key: "deviceSigningAvailable",
      label: "Device signing available",
      status: "ready",
      detail: null,
    });
  });

  it("maps unavailable signals to not-ready and surfaces the check reason", () => {
    const items = buildReadinessChecklist(
      inputs({
        localEncryption: unavailableEncryption(),
        deviceSigning: unavailableSigning(),
      }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.encryptionActive.status).toBe("not-ready");
    expect(byKey.encryptionActive.detail).toBe(unavailableEncryption().reason);
    expect(byKey.deviceSigningAvailable.status).toBe("not-ready");
    expect(byKey.deviceSigningAvailable.detail).toBe(unavailableSigning().reason);
  });

  it("reports items without an implemented signal as pending, never as ready", () => {
    const items = buildReadinessChecklist(inputs());
    const pendingKeys = items
      .filter((item) => item.status === "pending")
      .map((item) => item.key);

    expect(pendingKeys).toEqual([
      "loggedIn",
      "deviceTrusted",
      "eventSelected",
      "localCacheComplete",
      "lastSyncCompleted",
    ]);
    for (const item of items) {
      if (item.status === "pending") {
        expect(item.detail).toBe("Not available yet in this build.");
      }
    }
  });
});

describe("summarizeReadiness", () => {
  it("counts items by status", () => {
    const summary = summarizeReadiness(buildReadinessChecklist(inputs()));

    expect(summary).toEqual({ ready: 3, notReady: 0, pending: 5, total: 8 });
  });

  it("counts a failing capability as not ready", () => {
    const summary = summarizeReadiness(
      buildReadinessChecklist(inputs({ localEncryption: unavailableEncryption() })),
    );

    expect(summary).toEqual({ ready: 2, notReady: 1, pending: 5, total: 8 });
  });
});

describe("trusted server known", () => {
  it("is ready once this device knows which node it works against", () => {
    const items = buildReadinessChecklist(inputs());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.trustedServerKnown.status).toBe("ready");
    expect(byKey.trustedServerKnown.detail).toBe("https://onsite.example.org");
  });

  it("is not ready while the device is falling back to the development default", () => {
    const items = buildReadinessChecklist(inputs({ node: unknownNode() }));
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.trustedServerKnown.status).toBe("not-ready");
    expect(byKey.trustedServerKnown.detail).toContain("No node is configured");
  });

  it("says so when the device is pointed somewhere other than the serving node", () => {
    const items = buildReadinessChecklist(
      inputs({
        node: {
          url: "https://onsite.example.org",
          source: "configured",
          servedUrl: "https://central.example.org",
          overridesServingNode: true,
        },
      }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.trustedServerKnown.status).toBe("ready");
    expect(byKey.trustedServerKnown.detail).toContain(
      "not the node that served this app",
    );
  });

  it("reflects a node set on this device", () => {
    setNodeUrl("https://onsite.example.org");

    const items = resolveReadinessChecklist(capableScope());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.trustedServerKnown.status).toBe("ready");
    expect(byKey.trustedServerKnown.detail).toBe("https://onsite.example.org");
  });
});

describe("resolveReadinessChecklist", () => {
  it("probes a fully capable scope so encryption and signing are ready", () => {
    const items = resolveReadinessChecklist(capableScope());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.encryptionActive.status).toBe("ready");
    expect(byKey.deviceSigningAvailable.status).toBe("ready");
  });

  it("fails closed for an empty scope", () => {
    const items = resolveReadinessChecklist({});
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.encryptionActive.status).toBe("not-ready");
    expect(byKey.deviceSigningAvailable.status).toBe("not-ready");
  });
});
