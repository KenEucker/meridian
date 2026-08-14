/**
 * Tests over the iOS simulator choice (M19.21).
 *
 * The properties held here are the ones a developer notices when they are
 * wrong: an already booted simulator is reused rather than displaced, a
 * runtime an Xcode upgrade left behind is never chosen, and a machine that
 * cannot run the app says so in a sentence that names the fix.
 */
import assert from "node:assert/strict";
import { test } from "node:test";

import {
  appIdFromCapacitorConfig,
  availableSimulators,
  chooseSimulator,
  describeSimulator,
} from "./ios-simulators.mjs";

function simctlList(runtimes) {
  return { devices: runtimes };
}

const TWO_RUNTIMES = simctlList({
  "com.apple.CoreSimulator.SimRuntime.iOS-17-5": [
    { udid: "old-pro", name: "iPhone 16 Pro", state: "Shutdown", isAvailable: true },
  ],
  "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
    { udid: "new-pro", name: "iPhone 16 Pro", state: "Shutdown", isAvailable: true },
    { udid: "new-se", name: "iPhone SE (3rd generation)", state: "Shutdown", isAvailable: true },
  ],
  "com.apple.CoreSimulator.SimRuntime.watchOS-11-2": [
    { udid: "watch", name: "Apple Watch Series 10", state: "Shutdown", isAvailable: true },
  ],
});

test("lists available iOS simulators, newest runtime first", () => {
  assert.deepEqual(
    availableSimulators(TWO_RUNTIMES).map((simulator) => simulator.udid),
    ["new-pro", "new-se", "old-pro"],
  );
});

test("drops non-iOS runtimes and unavailable devices", () => {
  const list = simctlList({
    "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
      { udid: "kept", name: "iPhone 16", state: "Shutdown", isAvailable: true },
      { udid: "stale", name: "iPhone 14", state: "Shutdown", isAvailable: false },
    ],
    "com.apple.CoreSimulator.SimRuntime.tvOS-18-2": [
      { udid: "tv", name: "Apple TV", state: "Shutdown", isAvailable: true },
    ],
  });
  assert.deepEqual(
    availableSimulators(list).map((simulator) => simulator.udid),
    ["kept"],
  );
});

test("compares runtime versions numerically rather than as text", () => {
  const list = simctlList({
    "com.apple.CoreSimulator.SimRuntime.iOS-18-10": [
      { udid: "newer", name: "iPhone 16", state: "Shutdown", isAvailable: true },
    ],
    "com.apple.CoreSimulator.SimRuntime.iOS-18-9": [
      { udid: "older", name: "iPhone 16", state: "Shutdown", isAvailable: true },
    ],
  });
  assert.deepEqual(
    availableSimulators(list).map((simulator) => simulator.udid),
    ["newer", "older"],
  );
});

test("empty or absent simctl output is not an error", () => {
  assert.deepEqual(availableSimulators({}), []);
  assert.deepEqual(availableSimulators(simctlList({})), []);
});

test("prefers a booted simulator over the newest runtime", () => {
  // The developer is looking at the booted one. Choosing the newer shutdown
  // device would install the app in a window nobody has open.
  const simulators = availableSimulators(
    simctlList({
      "com.apple.CoreSimulator.SimRuntime.iOS-17-5": [
        { udid: "booted-old", name: "iPhone 16 Pro", state: "Booted", isAvailable: true },
      ],
      "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
        { udid: "new", name: "iPhone 16 Pro", state: "Shutdown", isAvailable: true },
      ],
    }),
  );
  assert.equal(chooseSimulator(simulators).simulator.udid, "booted-old");
});

test("with nothing booted, takes the newest runtime's first iPhone", () => {
  assert.equal(chooseSimulator(availableSimulators(TWO_RUNTIMES)).simulator.udid, "new-pro");
});

test("does not default to an iPad", () => {
  const simulators = availableSimulators(
    simctlList({
      "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
        { udid: "pad", name: "iPad Pro 13-inch (M4)", state: "Shutdown", isAvailable: true },
        { udid: "phone", name: "iPhone 16", state: "Shutdown", isAvailable: true },
      ],
    }),
  );
  assert.equal(chooseSimulator(simulators).simulator.udid, "phone");
});

test("a requested device name matches case-insensitively, preferring a booted one", () => {
  const simulators = availableSimulators(
    simctlList({
      "com.apple.CoreSimulator.SimRuntime.iOS-17-5": [
        { udid: "booted-old", name: "iPhone 16 Pro", state: "Booted", isAvailable: true },
      ],
      "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
        { udid: "new", name: "iPhone 16 Pro", state: "Shutdown", isAvailable: true },
      ],
    }),
  );
  assert.equal(chooseSimulator(simulators, { device: "iphone 16 pro" }).simulator.udid, "booted-old");
});

test("a requested udid wins over a booted simulator", () => {
  const simulators = availableSimulators(
    simctlList({
      "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
        { udid: "booted", name: "iPhone 16 Pro", state: "Booted", isAvailable: true },
        { udid: "asked-for", name: "iPhone SE (3rd generation)", state: "Shutdown", isAvailable: true },
      ],
    }),
  );
  assert.equal(chooseSimulator(simulators, { udid: "asked-for" }).simulator.udid, "asked-for");
});

test("an unknown device name or udid is refused, not silently substituted", () => {
  const simulators = availableSimulators(TWO_RUNTIMES);
  const byName = chooseSimulator(simulators, { device: "Pixel 9" });
  assert.equal(byName.simulator, null);
  assert.match(byName.problem, /named "Pixel 9"/);
  assert.match(byName.problem, /--list/);

  const byUdid = chooseSimulator(simulators, { udid: "nonexistent" });
  assert.equal(byUdid.simulator, null);
  assert.match(byUdid.problem, /nonexistent/);
});

test("a Mac with no simulators is told how to get one", () => {
  const { simulator, problem } = chooseSimulator([]);
  assert.equal(simulator, null);
  assert.match(problem, /no available iOS simulators/i);
  assert.match(problem, /Xcode/);
});

test("iOS simulators but no iPhone is refused with the flag that gets past it", () => {
  const simulators = availableSimulators(
    simctlList({
      "com.apple.CoreSimulator.SimRuntime.iOS-18-3": [
        { udid: "pad", name: "iPad mini (A17 Pro)", state: "Shutdown", isAvailable: true },
      ],
    }),
  );
  const { simulator, problem } = chooseSimulator(simulators);
  assert.equal(simulator, null);
  assert.match(problem, /--device/);
});

test("describes a simulator by name, runtime, and state", () => {
  const [simulator] = availableSimulators(TWO_RUNTIMES);
  assert.equal(describeSimulator(simulator), "iPhone 16 Pro (iOS 18.3, Shutdown)");
});

test("reads the application identifier out of the Capacitor configuration", () => {
  assert.equal(
    appIdFromCapacitorConfig('const config: CapacitorConfig = {\n  appId: "org.meridian.field",\n};'),
    "org.meridian.field",
  );
  assert.equal(appIdFromCapacitorConfig("const config = { appName: 'Meridian Field' };"), null);
});
