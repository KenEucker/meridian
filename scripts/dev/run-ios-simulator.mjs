#!/usr/bin/env node
/**
 * Run Meridian Field on an iOS simulator (M19.21 / spec 26.4).
 *
 * Seeing the packaged app on iOS is otherwise an Xcode session: open the
 * project, pick a destination, press run. This does the same three things
 * from the command line — build the committed Capacitor project for the
 * simulator, install it, launch it — so a change to the shared client can be
 * looked at on a phone-shaped screen without leaving the terminal.
 *
 * Nothing here signs anything, and that is the point worth knowing: a
 * simulator build is signed ad hoc by the toolchain ("Sign to Run Locally"),
 * so this needs no Apple Developer Program membership and no credential from
 * technical spec 26.5's inventory. The release path
 * (`corepack pnpm run mobile:ios:release`) is the one that needs those, and
 * this script is deliberately not a way around it: a simulator build is not
 * distributable and cannot be installed on a physical device.
 *
 * Like the release script, this packages an already built client artifact
 * (technical spec 26.4) rather than building one implicitly — a missing web
 * bundle is a refusal that names `mobile:cap:sync`, not a trigger.
 *
 * Usage:
 *   corepack pnpm run mobile:ios:simulator
 *   corepack pnpm run mobile:ios:simulator -- --device "iPhone SE (3rd generation)"
 *
 * The pnpm script syncs the client first, so a run always shows current code.
 * Listing devices does not need that and is quicker invoked directly:
 *   node scripts/dev/run-ios-simulator.mjs --list
 */

import { spawnSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { appIdFromCapacitorConfig } from "./capacitor-app.mjs";
import { availableSimulators, chooseSimulator, describeSimulator } from "./ios-simulators.mjs";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const mobileDir = join(repositoryRoot, "apps", "mobile");
const iosProject = join(mobileDir, "ios", "App", "App.xcodeproj");
const webBundleIndex = join(mobileDir, "ios", "App", "App", "public", "index.html");
const capacitorConfig = join(mobileDir, "capacitor.config.ts");
// Gitignored by apps/mobile/ios/.gitignore, so a run leaves nothing committable.
const derivedData = join(mobileDir, "ios", "DerivedData");
const CONFIGURATION = "Debug";
const SCHEME = "App";

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
    } else if (argument === "--device" || argument === "--udid") {
      const value = argv[index + 1];
      if (!value || value.startsWith("--")) {
        fail(`${argument} needs a value, for example --device "iPhone 16 Pro".`);
      }
      request[argument.slice(2)] = value;
      index += 1;
    } else {
      fail(`Unrecognized argument \`${argument}\`. Accepts --list, --device <name>, --udid <id>.`);
    }
  }
  return request;
}

/** Run a command, inheriting output, and stop the run if it fails. */
function run(command, args, { cwd = repositoryRoot } = {}) {
  const result = spawnSync(command, args, { cwd, stdio: "inherit" });
  if (result.error?.code === "ENOENT") {
    fail(`\`${command}\` is not installed or not on PATH.`);
  }
  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

/** Run a command and capture stdout, for the ones this script reads back. */
function capture(command, args) {
  const result = spawnSync(command, args, { encoding: "utf8" });
  if (result.error?.code === "ENOENT") {
    fail(`\`${command}\` is not installed or not on PATH.`);
  }
  if (result.status !== 0) {
    fail(`\`${command} ${args.join(" ")}\` failed:\n${result.stderr?.trim() ?? ""}`);
  }
  return result.stdout;
}

// Xcode and the iOS simulators run on macOS alone. Saying so here beats an
// `xcrun: not found` several steps in.
if (process.platform !== "darwin") {
  fail(
    `Running Meridian Field on an iOS simulator needs macOS and Xcode; this is ${process.platform}.\n` +
      "On other platforms, use the Android emulator or run the Field client in a browser " +
      "with `corepack pnpm run client:dev`.",
  );
}

const request = parseArguments(process.argv.slice(2));

let devices;
try {
  devices = JSON.parse(capture("xcrun", ["simctl", "list", "devices", "--json"]));
} catch (error) {
  fail(`Could not read the simulator list from simctl: ${error.message}`);
}

const simulators = availableSimulators(devices);

if (request.list) {
  if (simulators.length === 0) {
    fail("This Mac has no available iOS simulators. Install a simulator runtime in Xcode under Settings → Components.");
  }
  for (const simulator of simulators) {
    console.log(`${simulator.udid}  ${describeSimulator(simulator)}`);
  }
  process.exit(0);
}

if (!existsSync(webBundleIndex)) {
  fail(
    "The copied Meridian Field web bundle is missing at ios/App/App/public.\n" +
      "Run `corepack pnpm run mobile:cap:sync` first (technical spec 26.4).",
  );
}

const appId = appIdFromCapacitorConfig(readFileSync(capacitorConfig, "utf8"));
if (!appId) {
  fail(`No appId found in ${capacitorConfig}. That file is where the application identifier is declared.`);
}

const { simulator, problem } = chooseSimulator(simulators, request);
if (!simulator) {
  fail(problem);
}

console.log(`Meridian Field → ${describeSimulator(simulator)}`);

if (!simulator.booted) {
  run("xcrun", ["simctl", "boot", simulator.udid]);
}

// Bring up the Simulator window. Without this the app is installed and
// running on a device nobody can see, which reads as nothing having happened.
run("open", ["-a", "Simulator"]);

run("xcodebuild", [
  "-project",
  iosProject,
  "-scheme",
  SCHEME,
  "-configuration",
  CONFIGURATION,
  "-destination",
  `platform=iOS Simulator,id=${simulator.udid}`,
  "-derivedDataPath",
  derivedData,
  "build",
]);

const builtApp = join(derivedData, "Build", "Products", `${CONFIGURATION}-iphonesimulator`, `${SCHEME}.app`);
if (!existsSync(builtApp)) {
  fail(`The build reported success but produced no application at ${builtApp}.`);
}

run("xcrun", ["simctl", "install", simulator.udid, builtApp]);
run("xcrun", ["simctl", "launch", simulator.udid, appId]);

console.log(
  `\n${appId} is running on ${simulator.name}.\n` +
    "It has no node configured yet: open the menu and follow \"Connect this device to a node\", " +
    "pointing it at a node this Mac can reach (`corepack pnpm run server:dev` serves one at " +
    "http://localhost:8000, which the simulator shares).",
);
