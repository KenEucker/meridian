import { afterEach, describe, expect, it } from "vitest";

import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";
import {
  buildReadinessChecklist,
  resolveReadinessChecklist,
  summarizeReadiness,
  type ReadinessCacheSignal,
  type ReadinessChecklistInputs,
  type ReadinessDeviceSignal,
  type ReadinessItemKey,
  type ReadinessSessionSignal,
  type ReadinessSyncSignal,
  type ReadinessWorkstationSignal,
} from "@/readiness/checklist";
import type { DeviceSigningReadiness } from "@/readiness/deviceSigning";
import type { LocalEncryptionReadiness } from "@/readiness/localEncryption";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSessionFixture";

afterEach(() => {
  clearNodeUrl();
  clearClientSession();
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

/** Signed in, at an event, straight from the node. */
function liveSession(
  overrides: Partial<ReadinessSessionSignal> = {},
): ReadinessSessionSignal {
  return {
    signedIn: true,
    userName: "Dana Ranger",
    cached: false,
    refusedDetail: null,
    eventId: "event-1",
    eventLabel: "Emberfall Backcountry 2027",
    nodeLocked: false,
    ...overrides,
  };
}

/** No session has ever been established on this device. */
function noSession(): ReadinessSessionSignal {
  return {
    signedIn: false,
    userName: null,
    cached: false,
    refusedDetail: null,
    eventId: null,
    eventLabel: null,
    nodeLocked: false,
  };
}

/** An ordinary personal device, which is not a shared workstation at all. */
function personalDevice(): ReadinessWorkstationSignal {
  return {
    isSharedWorkstation: false,
    trusted: null,
    workstationName: null,
    fromStoredAnswer: false,
  };
}

/** A machine the node vouches for by name (M18.32; technical spec 13.1). */
function trustedWorkstation(
  overrides: Partial<ReadinessWorkstationSignal> = {},
): ReadinessWorkstationSignal {
  return {
    isSharedWorkstation: true,
    trusted: true,
    workstationName: "onsite-command-1",
    fromStoredAnswer: false,
    ...overrides,
  };
}

/** A personal device the node holds an active trust for (AUTH-024). */
function trustedDevice(
  overrides: Partial<ReadinessDeviceSignal> = {},
): ReadinessDeviceSignal {
  return {
    named: true,
    trusted: true,
    state: "trusted",
    label: "Dana's phone",
    trustedUntil: "2027-08-15T00:00:00.000Z",
    cached: false,
    ...overrides,
  };
}

/** A session that names no device, which is what a workstation key does. */
function noDevice(): ReadinessDeviceSignal {
  return {
    named: false,
    trusted: false,
    state: "untrusted",
    label: null,
    trustedUntil: null,
    cached: false,
  };
}

/** A read set held and still inside the window it may be served in. */
function usableCache(
  overrides: Partial<ReadinessCacheSignal> = {},
): ReadinessCacheSignal {
  return {
    usable: true,
    held: true,
    reason: null,
    storedAt: "2027-07-04T18:00:00.000Z",
    ...overrides,
  };
}

/** Refreshed, with nothing left in the outbox. */
function syncedUp(
  overrides: Partial<ReadinessSyncSignal> = {},
): ReadinessSyncSignal {
  return {
    refreshedAt: "2027-07-04T18:00:00.000Z",
    failedAt: null,
    unsentCommands: 0,
    rejectedCommands: 0,
    ...overrides,
  };
}

function inputs(
  overrides: Partial<ReadinessChecklistInputs> = {},
): ReadinessChecklistInputs {
  return {
    localEncryption: availableEncryption(),
    deviceSigning: availableSigning(),
    node: configuredNode(),
    session: liveSession(),
    workstation: personalDevice(),
    device: trustedDevice(),
    cache: usableCache(),
    sync: syncedUp(),
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

  /**
   * Every one of the eight items has a signal behind it now. The three that
   * used to report "Not available yet in this build" — device trust on a
   * personal device, the local cache, and the last sync — are answered from the
   * session document, the offline read set store, and the two sync directions.
   */
  it("answers every section 14 item rather than reporting any as unimplemented", () => {
    const items = buildReadinessChecklist(inputs());

    expect(items.filter((item) => item.status === "pending")).toEqual([]);
    for (const item of items) {
      expect(item.detail).not.toBe("Not available yet in this build.");
    }
  });

  /**
   * `pending` survives for the case it was always for: a signal that has not
   * answered yet. A session naming no device is not a device that failed a
   * check, and saying so as a failure would send a technician after hardware
   * that is fine.
   */
  it("reports a session that names no device as pending rather than as a failure", () => {
    const items = buildReadinessChecklist(inputs({ device: noDevice() }));
    const trusted = items.find((item) => item.key === "deviceTrusted")!;

    expect(trusted.status).toBe("pending");
    expect(trusted.detail).toBe("This session names no device to report trust for.");
  });
});

/**
 * `device trusted` on a personal device (AUTH-021, AUTH-024; data/API 12.2).
 *
 * The node establishes trust at sign-in and gives it six weeks from the last
 * one, so what the item has to distinguish is never-trusted, lapsed, and
 * revoked: the first two are fixed by signing in and the third is not.
 */
describe("personal device trust", () => {
  it("is ready when the node holds an active trust, and names when it lapses", () => {
    const items = buildReadinessChecklist(inputs());
    const trusted = items.find((item) => item.key === "deviceTrusted")!;

    expect(trusted.status).toBe("ready");
    expect(trusted.detail).toContain("Dana's phone is trusted for your account");
    expect(trusted.detail).toContain("2027-08-15T00:00:00.000Z");
  });

  it("says the trust is the node's last answer when the session is cached", () => {
    const items = buildReadinessChecklist(
      inputs({ device: trustedDevice({ cached: true }) }),
    );

    expect(items.find((item) => item.key === "deviceTrusted")!.detail).toContain(
      "could not be reached to confirm",
    );
  });

  it("tells a lapsed trust from a revoked one, because one is fixable by signing in", () => {
    const lapsed = buildReadinessChecklist(
      inputs({
        device: trustedDevice({ trusted: false, state: "expired" }),
      }),
    ).find((item) => item.key === "deviceTrusted")!;

    expect(lapsed.status).toBe("not-ready");
    expect(lapsed.detail).toContain("Signing in again renews it");

    const revoked = buildReadinessChecklist(
      inputs({
        device: trustedDevice({ trusted: false, state: "revoked" }),
      }),
    ).find((item) => item.key === "deviceTrusted")!;

    expect(revoked.status).toBe("not-ready");
    expect(revoked.detail).toContain("An administrator has to restore it");
  });

  /*
   * A machine that is a shared workstation is still answered by the workstation
   * signal, whatever the session says about a device. The two are different
   * kinds of trust and 13.1 keeps them apart.
   */
  it("leaves a shared workstation to the workstation signal", () => {
    const items = buildReadinessChecklist(
      inputs({ workstation: trustedWorkstation(), device: noDevice() }),
    );
    const trusted = items.find((item) => item.key === "deviceTrusted")!;

    expect(trusted.status).toBe("ready");
    expect(trusted.detail).toContain("onsite-command-1");
  });
});

/**
 * `local cache complete` (M18.46 through M18.50; technical spec 9.3, 11A.4).
 *
 * Complete is "the set the node composed for this caller, still inside the
 * window it may be served in" — never a list of sections, because the set is
 * composed per caller and a staff member holding no Logistics index is holding
 * exactly what they should.
 */
describe("local cache complete", () => {
  it("is ready when the device holds a servable set, and says when it was stored", () => {
    const item = buildReadinessChecklist(inputs()).find(
      (entry) => entry.key === "localCacheComplete",
    )!;

    expect(item.status).toBe("ready");
    expect(item.detail).toContain("2027-07-04T18:00:00.000Z");
  });

  it("is not ready when the device holds nothing yet", () => {
    const item = buildReadinessChecklist(
      inputs({
        cache: usableCache({
          usable: false,
          held: false,
          reason: "nothing_held",
          storedAt: null,
        }),
      }),
    ).find((entry) => entry.key === "localCacheComplete")!;

    expect(item.status).toBe("not-ready");
    expect(item.detail).toContain("holds no offline copy yet");
  });

  /*
   * A set past its event window is not a stale copy to disclose — 11A.4 refuses
   * to serve it, so every surface on the device is already answering as though
   * it holds nothing, and the checklist says the same thing they do.
   */
  it("is not ready when the set is past the window it may be served in", () => {
    const item = buildReadinessChecklist(
      inputs({
        cache: usableCache({ usable: false, reason: "event_window_ended" }),
      }),
    ).find((entry) => entry.key === "localCacheComplete")!;

    expect(item.status).toBe("not-ready");
    expect(item.detail).toContain("event that has ended");
  });
});

/**
 * `last sync completed`, in both directions (M18.49 pulling, M16.10 pushing).
 *
 * One item, because the person reading it is asking one question: is this
 * device's work where it needs to be.
 */
describe("last sync completed", () => {
  it("is ready when the set has refreshed and the outbox is empty", () => {
    const item = buildReadinessChecklist(inputs()).find(
      (entry) => entry.key === "lastSyncCompleted",
    )!;

    expect(item.status).toBe("ready");
    expect(item.detail).toContain("Everything recorded here has been sent");
    expect(item.detail).toContain("2027-07-04T18:00:00.000Z");
  });

  it("is not ready while the device is still holding work, and says how much", () => {
    const one = buildReadinessChecklist(
      inputs({ sync: syncedUp({ unsentCommands: 1 }) }),
    ).find((entry) => entry.key === "lastSyncCompleted")!;

    expect(one.status).toBe("not-ready");
    expect(one.detail).toBe(
      "1 action recorded on this device has not reached the node yet.",
    );

    const several = buildReadinessChecklist(
      inputs({ sync: syncedUp({ unsentCommands: 3 }) }),
    ).find((entry) => entry.key === "lastSyncCompleted")!;

    expect(several.detail).toBe(
      "3 actions recorded on this device have not reached the node yet.",
    );
  });

  /*
   * A refusal outranks unsent work: it will not clear on its own, and the point
   * of saying it here is that it is waiting in the outbox for a person.
   */
  it("reports a refusal ahead of unsent work", () => {
    const item = buildReadinessChecklist(
      inputs({ sync: syncedUp({ unsentCommands: 2, rejectedCommands: 1 }) }),
    ).find((entry) => entry.key === "lastSyncCompleted")!;

    expect(item.status).toBe("not-ready");
    expect(item.detail).toBe(
      "1 action the node refused is waiting for you to deal with it.",
    );
  });

  it("is not ready when the device has never synced", () => {
    const item = buildReadinessChecklist(
      inputs({ sync: syncedUp({ refreshedAt: null }) }),
    ).find((entry) => entry.key === "lastSyncCompleted")!;

    expect(item.status).toBe("not-ready");
    expect(item.detail).toBe("This device has not synced with the node yet.");
  });

  /*
   * A failed attempt after a successful one does not undo the successful one.
   * The device did sync; it also tried again and could not, and both are worth
   * saying to somebody deciding whether to walk back into coverage.
   */
  it("keeps a completed sync ready while naming a later failed attempt", () => {
    const item = buildReadinessChecklist(
      inputs({ sync: syncedUp({ failedAt: "2027-07-04T19:00:00.000Z" }) }),
    ).find((entry) => entry.key === "lastSyncCompleted")!;

    expect(item.status).toBe("ready");
    expect(item.detail).toContain("A later attempt did not reach the node");
  });
});

/*
 * `device trusted` (M18.32; technical spec 13.1; data/API 12.2).
 *
 * Answered for a shared workstation, which 13.1 calls "a special kind of trusted
 * device" and whose trust the node publishes, and pending for a personal device,
 * whose `device_trusts` row no endpoint serves.
 */
describe("device trusted", () => {
  it("is ready and names the workstation the node vouches for", () => {
    const items = buildReadinessChecklist(
      inputs({ workstation: trustedWorkstation() }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.deviceTrusted.status).toBe("ready");
    expect(byKey.deviceTrusted.detail).toContain("onsite-command-1");
  });

  it("is not ready when the node holds no trusted workstation for this machine", () => {
    // The one case on this checklist where device trust can honestly fail: the
    // machine claims to be a shared workstation and the node refuses to answer
    // for it, which is a technician's problem rather than a pending feature.
    const items = buildReadinessChecklist(
      inputs({ workstation: trustedWorkstation({ trusted: false }) }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.deviceTrusted.status).toBe("not-ready");
    expect(byKey.deviceTrusted.detail).toContain("no trusted shared workstation");
  });

  it("stays ready on the stored answer and says which answer it is", () => {
    // An unreachable node is the ordinary state of a machine on site. Readiness
    // is advisory and must not nag (technical spec 14), and the node enforces
    // trust on every request whatever this says.
    const items = buildReadinessChecklist(
      inputs({ workstation: trustedWorkstation({ fromStoredAnswer: true }) }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.deviceTrusted.status).toBe("ready");
    expect(byKey.deviceTrusted.detail).toContain("last answer");
  });

  it("is pending, not failing, while the node has not answered yet", () => {
    const items = buildReadinessChecklist(
      inputs({ workstation: trustedWorkstation({ trusted: null }) }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.deviceTrusted.status).toBe("pending");
  });

  it("does not read a device-bound token as trust on a personal device", () => {
    // A token proves this device may call the node; trust is the separate
    // six-week relationship in `device_trusts`. The item follows the node's own
    // verdict, so a signed-in device whose trust the node does not hold reads
    // not-ready — reporting the token as trust would be a false pass on the
    // item least worth faking.
    const items = buildReadinessChecklist(
      inputs({
        workstation: personalDevice(),
        session: liveSession(),
        device: trustedDevice({ trusted: false, state: "untrusted" }),
      }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("ready");
    expect(byKey.deviceTrusted.status).toBe("not-ready");
  });
});

describe("logged in", () => {
  it("is ready and names the user for a session the node just answered", () => {
    const items = buildReadinessChecklist(inputs());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("ready");
    expect(byKey.loggedIn.detail).toBe("Signed in as Dana Ranger.");
  });

  it("is ready for a cached session and says where the permissions came from", () => {
    const items = buildReadinessChecklist(
      inputs({ session: liveSession({ cached: true }) }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("ready");
    expect(byKey.loggedIn.detail).toContain("last received");
  });

  it("is not ready, and says to sign in, when this device holds no session", () => {
    const items = buildReadinessChecklist(inputs({ session: noSession() }));
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("not-ready");
    expect(byKey.loggedIn.detail).toContain("Sign in");
  });

  it("is not ready, and says to reconnect, when a held session is refused", () => {
    const items = buildReadinessChecklist(
      inputs({
        session: liveSession({
          signedIn: false,
          eventId: null,
          eventLabel: null,
          refusedDetail:
            "The event this device cached its permissions for has ended. Reconnect to the node to continue.",
        }),
      }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("not-ready");
    expect(byKey.loggedIn.detail).toContain("Reconnect to the node");
  });
});

describe("event selected", () => {
  it("is ready and names the event the session resolved at", () => {
    const items = buildReadinessChecklist(inputs());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.eventSelected.status).toBe("ready");
    expect(byKey.eventSelected.detail).toBe("Emberfall Backcountry 2027");
  });

  it("is ready on a locked node, where nobody selected anything", () => {
    const items = buildReadinessChecklist(
      inputs({ session: liveSession({ nodeLocked: true }) }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.eventSelected.status).toBe("ready");
    expect(byKey.eventSelected.detail).toBe(
      "Emberfall Backcountry 2027. This node is locked to it.",
    );
  });

  it("is not ready when a signed-in device has no event in effect", () => {
    const items = buildReadinessChecklist(
      inputs({ session: liveSession({ eventId: null, eventLabel: null }) }),
    );
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.eventSelected.status).toBe("not-ready");
    expect(byKey.eventSelected.detail).toBe(
      "No event is in effect on this device.",
    );
  });

  it("points a device with no session at signing in", () => {
    const items = buildReadinessChecklist(inputs({ session: noSession() }));
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.eventSelected.status).toBe("not-ready");
    expect(byKey.eventSelected.detail).toContain("Sign in");
  });
});

describe("summarizeReadiness", () => {
  it("counts items by status", () => {
    const summary = summarizeReadiness(buildReadinessChecklist(inputs()));

    expect(summary).toEqual({ ready: 8, notReady: 0, pending: 0, total: 8 });
  });

  it("counts a failing capability as not ready", () => {
    const summary = summarizeReadiness(
      buildReadinessChecklist(inputs({ localEncryption: unavailableEncryption() })),
    );

    expect(summary).toEqual({ ready: 7, notReady: 1, pending: 0, total: 8 });
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

  it("reads logged in and event selected off the session this client holds", () => {
    installLocalFieldSession();

    const items = resolveReadinessChecklist(capableScope());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("ready");
    expect(byKey.loggedIn.detail).toBe("Signed in as Local Field Author.");
    expect(byKey.eventSelected.status).toBe("ready");
    expect(byKey.eventSelected.detail).toContain("This node is locked to it.");
  });

  it("reports both as not ready on a device with no session", () => {
    const items = resolveReadinessChecklist(capableScope());
    const byKey = Object.fromEntries(items.map((item) => [item.key, item]));

    expect(byKey.loggedIn.status).toBe("not-ready");
    expect(byKey.eventSelected.status).toBe("not-ready");
  });
});
