/**
 * Choosing which iOS simulator to run Meridian Field on (M19.21).
 *
 * A developer asking to see the app on iOS does not want to name a device,
 * and the machines this runs on disagree about which devices exist: Xcode
 * ships whatever runtimes it was installed with, and a laptop that has been
 * through a few Xcode upgrades carries unavailable runtimes alongside current
 * ones. So the choice is made from what `simctl` reports rather than from a
 * device name written down here, which would be a name half the machines do
 * not have.
 *
 * The rule that matters is preferring a simulator that is already booted. A
 * developer who has one open is using it — booting a second one steals the
 * window and leaves the app installed somewhere they are not looking.
 *
 * Pure, and separated from the script that runs it, because the interesting
 * part is the choice rather than the spawning. The caller supplies parsed
 * `simctl list devices --json` output; everything below is arithmetic on it.
 */

/**
 * One simulator this machine can run.
 *
 * @typedef {object} Simulator
 * @property {string} udid
 * @property {string} name
 * @property {string} state `Booted`, `Shutdown`, and so on, as simctl spells it.
 * @property {boolean} booted
 * @property {number[]} iosVersion Runtime version, most significant first.
 */

const IOS_RUNTIME = /\.SimRuntime\.iOS-([\d-]+)$/;

/** Compare two version tuples, newest first. */
function byNewestRuntime(left, right) {
  const length = Math.max(left.iosVersion.length, right.iosVersion.length);
  for (let index = 0; index < length; index += 1) {
    const difference = (right.iosVersion[index] ?? 0) - (left.iosVersion[index] ?? 0);
    if (difference !== 0) {
      return difference;
    }
  }
  return 0;
}

/**
 * The available iOS simulators in `simctl list devices --json` output,
 * newest runtime first and in simctl's own order within a runtime.
 *
 * Anything that is not an iOS runtime is dropped, because Meridian Field is
 * an iOS application and a watchOS device would only ever be a confusing
 * choice. So is anything simctl reports as unavailable: those are the entries
 * for runtimes an Xcode upgrade left behind, and booting one fails.
 *
 * @param {{devices?: Record<string, Array<Record<string, unknown>>>}} list
 * @returns {Simulator[]}
 */
export function availableSimulators(list) {
  const simulators = [];
  for (const [runtime, devices] of Object.entries(list?.devices ?? {})) {
    const match = IOS_RUNTIME.exec(runtime);
    if (!match) {
      continue;
    }
    const iosVersion = match[1].split("-").map(Number);
    for (const device of devices ?? []) {
      if (device.isAvailable === false) {
        continue;
      }
      simulators.push({
        udid: String(device.udid),
        name: String(device.name),
        state: String(device.state ?? "Unknown"),
        booted: device.state === "Booted",
        iosVersion,
      });
    }
  }
  return simulators.sort(byNewestRuntime);
}

/** `iPhone 16 Pro (iOS 18.3, Booted)`, for listing and for messages. */
export function describeSimulator(simulator) {
  return `${simulator.name} (iOS ${simulator.iosVersion.join(".")}, ${simulator.state})`;
}

/**
 * Pick the simulator to run on, or explain why none can be picked.
 *
 * With no request: an already booted simulator, else the newest runtime's
 * first iPhone. iPhone specifically — Meridian Field is used one-handed in a
 * field by somebody holding a phone, so an iPad is not the default even when
 * simctl lists one first.
 *
 * `problem` is a finished sentence rather than a code, because every caller
 * of this function is about to print it to a developer.
 *
 * @param {Simulator[]} simulators
 * @param {{udid?: string, device?: string}} [request]
 * @returns {{simulator: Simulator | null, problem: string | null}}
 */
export function chooseSimulator(simulators, request = {}) {
  if (simulators.length === 0) {
    return {
      simulator: null,
      problem:
        "This Mac has no available iOS simulators. Install a simulator runtime " +
        "in Xcode under Settings → Components, then rerun.",
    };
  }

  if (request.udid) {
    const found = simulators.find((simulator) => simulator.udid === request.udid);
    return found
      ? { simulator: found, problem: null }
      : {
          simulator: null,
          problem: `No available simulator has the udid ${request.udid}. Run with --list to see the ones this Mac has.`,
        };
  }

  if (request.device) {
    const wanted = request.device.toLowerCase();
    const matches = simulators.filter((simulator) => simulator.name.toLowerCase() === wanted);
    if (matches.length === 0) {
      return {
        simulator: null,
        problem: `No available simulator is named "${request.device}". Run with --list to see the ones this Mac has.`,
      };
    }
    // Several runtimes can carry the same device name. A booted one is the
    // one the developer is already looking at; otherwise the newest runtime.
    return { simulator: matches.find((simulator) => simulator.booted) ?? matches[0], problem: null };
  }

  const booted = simulators.find((simulator) => simulator.booted);
  if (booted) {
    return { simulator: booted, problem: null };
  }

  const iPhone = simulators.find((simulator) => simulator.name.startsWith("iPhone"));
  if (iPhone) {
    return { simulator: iPhone, problem: null };
  }

  return {
    simulator: null,
    problem:
      "This Mac has iOS simulators but no iPhone among them. Add an iPhone " +
      "simulator in Xcode under Settings → Components, or name a device with --device.",
  };
}

/**
 * The application identifier to install and launch, read from the committed
 * Capacitor configuration.
 *
 * `capacitor.config.ts` is the identity source of truth the native projects
 * are generated and tested against (`apps/mobile/src/nativeProjects.ts`), so
 * the identifier is read from it rather than repeated here, where it could
 * disagree with what a build actually produced.
 *
 * @param {string} text Contents of `apps/mobile/capacitor.config.ts`.
 * @returns {string | null}
 */
export function appIdFromCapacitorConfig(text) {
  const match = /\bappId:\s*["']([^"']+)["']/.exec(text);
  return match ? match[1] : null;
}
