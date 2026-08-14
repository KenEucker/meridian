/**
 * Tests over the Android SDK lookup and device choice (M19.21).
 *
 * The properties held here are the ones that decide whether the command works
 * on somebody else's machine: the SDK is found where each platform's
 * installer puts it, a device listed in a state it cannot be installed to is
 * reported as that rather than as absent, and a physical device is never
 * picked for somebody.
 */
import assert from "node:assert/strict";
import { test } from "node:test";

import {
  androidSdkCandidates,
  chooseAndroidTarget,
  parseAdbDevices,
  parseAvdNames,
} from "./android-devices.mjs";

test("prefers an exported SDK location over the platform default", () => {
  const candidates = androidSdkCandidates("darwin", { ANDROID_HOME: "/opt/sdk" }, "/Users/dev");
  assert.equal(candidates[0], "/opt/sdk");
  assert.ok(candidates.includes("/Users/dev/Library/Android/sdk"));
});

test("falls back to where each platform's installer puts the SDK", () => {
  assert.ok(androidSdkCandidates("darwin", {}, "/Users/dev").includes("/Users/dev/Library/Android/sdk"));
  assert.ok(androidSdkCandidates("linux", {}, "/home/dev").includes("/home/dev/Android/Sdk"));
  assert.ok(
    androidSdkCandidates("win32", { LOCALAPPDATA: "C:\\Users\\dev\\AppData\\Local" }, "C:\\Users\\dev").some(
      (candidate) => candidate.includes("Android"),
    ),
  );
});

test("lists no location twice", () => {
  const candidates = androidSdkCandidates(
    "darwin",
    { ANDROID_HOME: "/opt/sdk", ANDROID_SDK_ROOT: "/opt/sdk" },
    "/Users/dev",
  );
  assert.equal(candidates.filter((candidate) => candidate === "/opt/sdk").length, 1);
});

test("parses adb output, keeping the header out and the state in", () => {
  const devices = parseAdbDevices(
    ["List of devices attached", "emulator-5554\tdevice", "R58M12345\tunauthorized", ""].join("\n"),
  );
  assert.deepEqual(devices, [
    { serial: "emulator-5554", state: "device", emulator: true, usable: true },
    { serial: "R58M12345", state: "unauthorized", emulator: false, usable: false },
  ]);
});

test("empty adb output is not an error", () => {
  assert.deepEqual(parseAdbDevices("List of devices attached\n\n"), []);
  assert.deepEqual(parseAdbDevices(""), []);
});

test("prefers a running emulator over booting one", () => {
  const chosen = chooseAndroidTarget({
    devices: parseAdbDevices("List of devices attached\nemulator-5554\tdevice"),
    avds: ["Pixel_7_API_34"],
  });
  assert.equal(chosen.device.serial, "emulator-5554");
  assert.equal(chosen.avd, null);
});

test("boots the first virtual device when none is running", () => {
  const chosen = chooseAndroidTarget({ devices: [], avds: ["Pixel_7_API_34", "Pixel_3a_API_33"] });
  assert.equal(chosen.device, null);
  assert.equal(chosen.avd, "Pixel_7_API_34");
});

test("never installs to a physical device by default, but names it", () => {
  // Somebody with a phone plugged in for something else should not get a
  // debug build pushed onto it.
  const chosen = chooseAndroidTarget({
    devices: parseAdbDevices("List of devices attached\nR58M12345\tdevice"),
    avds: [],
  });
  assert.equal(chosen.device, null);
  assert.equal(chosen.avd, null);
  assert.match(chosen.problem, /--device R58M12345/);
});

test("a named physical device is installed to", () => {
  const chosen = chooseAndroidTarget(
    { devices: parseAdbDevices("List of devices attached\nR58M12345\tdevice"), avds: [] },
    { device: "R58M12345" },
  );
  assert.equal(chosen.device.serial, "R58M12345");
});

test("an unauthorized device is reported as that, not as missing", () => {
  const chosen = chooseAndroidTarget(
    { devices: parseAdbDevices("List of devices attached\nR58M12345\tunauthorized"), avds: [] },
    { device: "R58M12345" },
  );
  assert.equal(chosen.device, null);
  assert.match(chosen.problem, /unauthorized/);
  assert.match(chosen.problem, /USB debugging prompt/);
});

test("an unknown serial or avd name is refused", () => {
  const available = { devices: [], avds: ["Pixel_7_API_34"] };
  assert.match(chooseAndroidTarget(available, { device: "nope" }).problem, /nope/);
  assert.match(chooseAndroidTarget(available, { avd: "Pixel_9" }).problem, /"Pixel_9"/);
});

test("a machine with nothing at all is told how to get an emulator", () => {
  const { problem } = chooseAndroidTarget({ devices: [], avds: [] });
  assert.match(problem, /Device Manager/);
});

test("reads avd names, ignoring the emulator's advice lines", () => {
  assert.deepEqual(
    parseAvdNames("INFO | Storing crashdata in a file\nPixel_7_API_34\nPixel_3a_API_33\n"),
    ["Pixel_7_API_34", "Pixel_3a_API_33"],
  );
});
