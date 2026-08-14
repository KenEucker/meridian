#!/usr/bin/env node
/**
 * Run Meridian Field on an Android emulator (M19.21 / spec 26.4).
 *
 * The counterpart of `run-ios-simulator.mjs`: build the committed Capacitor
 * project, install it, launch it, so a change to the shared client can be
 * looked at on a phone-shaped screen without opening Android Studio.
 *
 * This builds the debug variant, which Gradle signs with the local debug
 * keystore, so it needs none of the `MERIDIAN_ANDROID_UPLOAD_*` credentials
 * technical spec 26.5 inventories — and it is not a way around them. A debug
 * APK is not what the Play track or the direct-install path take;
 * `mobile:android:release` is the only path that produces those.
 *
 * Unlike iOS this runs on every platform, and the awkwardness is elsewhere:
 * `adb` and `emulator` live inside an SDK that is usually not on the PATH, so
 * they are looked for where each platform's installer puts them rather than
 * demanded from the environment (`android-devices.mjs`).
 *
 * Like the release script, this packages an already built client artifact
 * (technical spec 26.4) rather than building one implicitly — a missing web
 * bundle is a refusal that names `mobile:cap:sync`, not a trigger.
 *
 * Usage:
 *   corepack pnpm run mobile:android:emulator
 *   corepack pnpm run mobile:android:emulator -- --avd Pixel_7_API_34
 *
 * The pnpm script syncs the client first, so a run always shows current code.
 * Listing devices does not need that and is quicker invoked directly:
 *   node scripts/dev/run-android-emulator.mjs --list
 */

import { spawn, spawnSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { homedir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import {
  androidSdkCandidates,
  chooseAndroidTarget,
  parseAdbDevices,
  parseAvdNames,
} from "./android-devices.mjs";
import { appIdFromCapacitorConfig } from "./capacitor-app.mjs";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const mobileDir = join(repositoryRoot, "apps", "mobile");
const androidDir = join(mobileDir, "android");
const webBundleIndex = join(androidDir, "app", "src", "main", "assets", "public", "index.html");
const capacitorConfig = join(mobileDir, "capacitor.config.ts");
const debugApk = join(androidDir, "app", "build", "outputs", "apk", "debug", "app-debug.apk");
const isWindows = process.platform === "win32";
// A cold emulator boot is slow — minutes on a machine without hardware
// acceleration — and giving up early sends somebody looking for a fault that
// is not there.
const BOOT_TIMEOUT_MS = 420_000;

function fail(message) {
  console.error(message);
  process.exit(1);
}

function parseArguments(argv) {
  const request = { list: false };
  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === "--list") {
      request.list = true;
    } else if (argument === "--device" || argument === "--avd") {
      const value = argv[index + 1];
      if (!value || value.startsWith("--")) {
        fail(`${argument} needs a value, for example --avd Pixel_7_API_34.`);
      }
      request[argument.slice(2)] = value;
      index += 1;
    } else {
      fail(`Unrecognized argument \`${argument}\`. Accepts --list, --avd <name>, --device <serial>.`);
    }
  }
  return request;
}

/** The SDK to use, found where this platform's installer puts it. */
function resolveAndroidSdk() {
  const candidates = androidSdkCandidates(process.platform, process.env, homedir());
  const found = candidates.find((candidate) => existsSync(join(candidate, "platform-tools")));
  if (!found) {
    fail(
      "No Android SDK found. Install it through Android Studio, or point ANDROID_HOME at an\n" +
        `existing one. Looked in:\n${candidates.map((candidate) => `  ${candidate}`).join("\n")}`,
    );
  }
  return found;
}

function toolPath(sdk, ...segments) {
  const path = join(sdk, ...segments) + (isWindows ? ".exe" : "");
  if (!existsSync(path)) {
    fail(`The Android SDK at ${sdk} has no ${segments.join("/")}. Install it in Android Studio's SDK Manager.`);
  }
  return path;
}

/** Run a command, inheriting output, and stop the run if it fails. */
function run(command, args, options = {}) {
  const result = spawnSync(command, args, { cwd: repositoryRoot, stdio: "inherit", ...options });
  if (result.error?.code === "ENOENT") {
    fail(`\`${command}\` is not installed or not on PATH.`);
  }
  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

/** Run a command and capture stdout, for the ones this script reads back. */
function capture(command, args, { allowFailure = false } = {}) {
  const result = spawnSync(command, args, { encoding: "utf8" });
  if (result.error?.code === "ENOENT") {
    fail(`\`${command}\` is not installed or not on PATH.`);
  }
  if (result.status !== 0 && !allowFailure) {
    fail(`\`${command}\` failed:\n${result.stderr?.trim() ?? ""}`);
  }
  return result.stdout ?? "";
}

const request = parseArguments(process.argv.slice(2));

const sdk = resolveAndroidSdk();
const adb = toolPath(sdk, "platform-tools", "adb");
const emulator = toolPath(sdk, "emulator", "emulator");

const attached = () => parseAdbDevices(capture(adb, ["devices"]));
const avds = parseAvdNames(capture(emulator, ["-list-avds"], { allowFailure: true }));

if (request.list) {
  const devices = attached();
  console.log("Attached:");
  console.log(
    devices.length === 0
      ? "  (nothing)"
      : devices.map((device) => `  ${device.serial}  ${device.state}`).join("\n"),
  );
  console.log("Virtual devices:");
  console.log(avds.length === 0 ? "  (none)" : avds.map((avd) => `  ${avd}`).join("\n"));
  process.exit(0);
}

if (!existsSync(webBundleIndex)) {
  fail(
    "The copied Meridian Field web bundle is missing at android/app/src/main/assets/public.\n" +
      "Run `corepack pnpm run mobile:cap:sync` first (technical spec 26.4).",
  );
}

const appId = appIdFromCapacitorConfig(readFileSync(capacitorConfig, "utf8"));
if (!appId) {
  fail(`No appId found in ${capacitorConfig}. That file is where the application identifier is declared.`);
}

const chosen = chooseAndroidTarget({ devices: attached(), avds }, request);
if (chosen.problem) {
  fail(chosen.problem);
}

let serial = chosen.device?.serial ?? null;

if (chosen.avd) {
  console.log(`Booting ${chosen.avd}…`);
  // Detached: the emulator outlives this script, the same way a simulator
  // left open on macOS does, so the next run reuses it instead of booting.
  spawn(emulator, ["-avd", chosen.avd], { detached: true, stdio: "ignore" }).unref();

  const deadline = Date.now() + BOOT_TIMEOUT_MS;
  while (Date.now() < deadline) {
    const running = attached().find((device) => device.emulator && device.usable);
    if (running) {
      const booted = capture(adb, ["-s", running.serial, "shell", "getprop", "sys.boot_completed"], {
        allowFailure: true,
      });
      if (booted.trim() === "1") {
        serial = running.serial;
        break;
      }
    }
    await new Promise((resolveWait) => setTimeout(resolveWait, 2000));
  }

  if (!serial) {
    fail(
      `${chosen.avd} did not finish booting within ${BOOT_TIMEOUT_MS / 1000}s.\n` +
        "It may still be coming up — `adb devices` shows `offline` until it is ready, and a\n" +
        "rerun uses a running emulator rather than booting another. An image whose ABI does\n" +
        "not match this machine never gets there; check the AVD in Android Studio's Device\n" +
        "Manager if it stays offline.",
    );
  }
}

console.log(`Meridian Field → ${serial}`);

/*
 * Gradle's own failure for a missing JDK is the java launcher's, several steps
 * in and pointing at java.com rather than at the version this project builds
 * with. macOS ships a `java` that exists and refuses, so a non-zero status
 * counts as missing here just as much as no such command does. Checked here
 * rather than at startup because listing devices needs no JDK.
 */
const javaVersion = spawnSync("java", ["-version"]);
if (javaVersion.error?.code === "ENOENT" || javaVersion.status !== 0) {
  fail(
    "No Java runtime found, and the Android build needs JDK 21.\n" +
      "Install it (`brew install openjdk@21` on macOS, or Android Studio's bundled JDK),\n" +
      "then rerun. See docs/process/release-packaging.md, Meridian Field Android app bundle and APK.",
  );
}

// Gradle finds the SDK through ANDROID_HOME, so the run works on a machine
// that has never exported one and never writes a local.properties into the
// committed project.
run(join(androidDir, isWindows ? "gradlew.bat" : "gradlew"), ["--no-daemon", "assembleDebug"], {
  cwd: androidDir,
  env: { ...process.env, ANDROID_HOME: sdk },
});

if (!existsSync(debugApk)) {
  fail(`The build reported success but produced no APK at ${debugApk}.`);
}

run(adb, ["-s", serial, "install", "-r", debugApk]);
run(adb, ["-s", serial, "shell", "monkey", "-p", appId, "-c", "android.intent.category.LAUNCHER", "1"]);

console.log(
  `\n${appId} is running on ${serial}.\n` +
    "It has no node configured yet: open the menu and follow \"Connect this device to a node\". " +
    "An emulator reaches this machine at http://10.0.2.2:8000 rather than localhost " +
    "(`corepack pnpm run server:dev` serves one there).",
);
