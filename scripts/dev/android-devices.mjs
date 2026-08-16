/**
 * Finding the Android SDK and choosing what to run Meridian Field on (M19.21).
 *
 * The counterpart of `ios-simulators.mjs`, and awkward in the one way iOS is
 * not: `xcrun` is on the PATH of every Mac with Xcode, while `adb` and
 * `emulator` are inside an SDK the installer does not put on the PATH and
 * whose location `ANDROID_HOME` is often not set to. A developer who has
 * Android Studio working still has a shell where `adb` is "command not
 * found", so the SDK is looked for where each platform's installer puts it
 * rather than demanded from the environment.
 *
 * Choosing what to run on has one rule worth stating: a physical device is
 * never picked by default. Somebody with a phone plugged in for something
 * else should not have a debug build pushed onto it because it happened to be
 * the only thing `adb` listed — `--device` names one on purpose.
 *
 * Pure, and separated from the script that runs it, because the interesting
 * part is the choice rather than the spawning.
 */

import { posix, win32 } from "node:path";

/**
 * One thing `adb devices` listed.
 *
 * @typedef {object} AdbDevice
 * @property {string} serial
 * @property {string} state `device` when usable; `offline`/`unauthorized` otherwise.
 * @property {boolean} emulator
 * @property {boolean} usable
 */

/**
 * Where each platform's Android SDK installer puts it, in the order to try.
 *
 * `ANDROID_HOME` and `ANDROID_SDK_ROOT` come first because somebody who set
 * one means it. The rest are the defaults, so a developer who has never
 * exported anything still gets a working command.
 *
 * @param {string} platform `process.platform`
 * @param {Record<string, string | undefined>} env
 * @param {string} home
 * @returns {string[]}
 */
export function androidSdkCandidates(platform, env, home) {
  const candidates = [env.ANDROID_HOME, env.ANDROID_SDK_ROOT];
  // Join with the separator of the platform being asked about, not the host's:
  // the default locations are claims about that platform's filesystem, and the
  // host's `path.join` would render a darwin default with backslashes when this
  // runs on Windows.
  const { join } = platform === "win32" ? win32 : posix;

  if (platform === "darwin") {
    candidates.push(join(home, "Library", "Android", "sdk"));
  } else if (platform === "win32") {
    candidates.push(join(env.LOCALAPPDATA ?? join(home, "AppData", "Local"), "Android", "Sdk"));
  } else {
    candidates.push(join(home, "Android", "Sdk"), join(home, "Android", "sdk"));
  }

  const seen = new Set();
  return candidates.filter((candidate) => {
    if (!candidate || seen.has(candidate)) {
      return false;
    }
    seen.add(candidate);
    return true;
  });
}

/**
 * Parse `adb devices` output.
 *
 * The first line is a header, and a device can be listed in a state it cannot
 * be installed to — `unauthorized` until somebody accepts the debugging
 * prompt on the phone, `offline` while it is still coming up. Those are kept
 * with `usable: false` rather than dropped, so the caller can say which
 * problem it is instead of reporting nothing attached.
 *
 * @param {string} text
 * @returns {AdbDevice[]}
 */
export function parseAdbDevices(text) {
  const devices = [];
  for (const rawLine of (text ?? "").split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === "" || line.startsWith("List of devices") || line.startsWith("*")) {
      continue;
    }
    const [serial, state] = line.split(/\s+/);
    if (!serial || !state) {
      continue;
    }
    devices.push({
      serial,
      state,
      emulator: serial.startsWith("emulator-"),
      usable: state === "device",
    });
  }
  return devices;
}

/**
 * Pick what to install to, or explain why nothing can be picked.
 *
 * With no request: a running emulator, else an AVD to boot (`avd` in the
 * result means "boot this first"). A physical device is reachable only by
 * name, for the reason in the module comment.
 *
 * @param {{devices: AdbDevice[], avds: string[]}} available
 * @param {{device?: string, avd?: string}} [request]
 * @returns {{device: AdbDevice | null, avd: string | null, problem: string | null}}
 */
export function chooseAndroidTarget({ devices, avds }, request = {}) {
  if (request.device) {
    const found = devices.find((device) => device.serial === request.device);
    if (!found) {
      return {
        device: null,
        avd: null,
        problem: `Nothing attached has the serial ${request.device}. Run with --list to see what adb reports.`,
      };
    }
    if (!found.usable) {
      return {
        device: null,
        avd: null,
        problem:
          `${found.serial} is ${found.state}, so nothing can be installed to it. ` +
          (found.state === "unauthorized"
            ? "Accept the USB debugging prompt on the device and rerun."
            : "Wait for it to finish starting and rerun."),
      };
    }
    return { device: found, avd: null, problem: null };
  }

  if (request.avd) {
    return avds.includes(request.avd)
      ? { device: null, avd: request.avd, problem: null }
      : {
          device: null,
          avd: null,
          problem: `No virtual device is named "${request.avd}". Run with --list to see the ones this machine has.`,
        };
  }

  const runningEmulator = devices.find((device) => device.emulator && device.usable);
  if (runningEmulator) {
    return { device: runningEmulator, avd: null, problem: null };
  }

  if (avds.length > 0) {
    return { device: null, avd: avds[0], problem: null };
  }

  const attached = devices.filter((device) => device.usable && !device.emulator);
  if (attached.length > 0) {
    // Deliberately not chosen for them — say it is there and how to ask for it.
    return {
      device: null,
      avd: null,
      problem:
        `This machine has no Android virtual device, and a physical device is never installed to by default. ` +
        `Create an emulator in Android Studio's Device Manager, or install to the attached device on purpose with ` +
        `--device ${attached[0].serial}.`,
    };
  }

  return {
    device: null,
    avd: null,
    problem:
      "This machine has no Android virtual device and nothing is attached. " +
      "Create an emulator in Android Studio's Device Manager, then rerun.",
  };
}

/** The AVD names in `emulator -list-avds` output. */
export function parseAvdNames(text) {
  return (text ?? "")
    .split(/\r?\n/)
    .map((line) => line.trim())
    // The emulator prints advice to stdout on some setups; a name has no spaces.
    .filter((line) => line !== "" && !line.includes(" "));
}
