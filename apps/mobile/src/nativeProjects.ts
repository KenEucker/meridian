/**
 * Readers over the committed Capacitor native platform projects (M19.21).
 *
 * Technical spec 26.4 commits the Android and iOS projects so the application
 * identifier, product name, version derivation, and permission declarations
 * are reviewable in a diff and identical on every machine that builds a
 * release. These helpers read those declarations back out of the native
 * project files so the config tests can hold them against one source of
 * truth: `capacitor.config.ts` for identity, the root `package.json` for the
 * version, and `nativePermissions.ts` for the permission inventory.
 *
 * Everything here is a pure reader over injectable file access, the same
 * shape as the kiosk packaging configuration (`apps/kiosk/src/packagingConfig.ts`),
 * so the tests assert what a native build would declare without running one.
 */

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

type ReadFile = typeof readFileSync;

const NUMERIC_VERSION = /^\d+\.\d+\.\d+$/;
const ROOT_PACKAGE_NAME = "meridian";

/** Repository-relative locations the native projects live at. */
export const ANDROID_APP_GRADLE = "android/app/build.gradle";
export const ANDROID_MANIFEST = "android/app/src/main/AndroidManifest.xml";
export const ANDROID_STRINGS = "android/app/src/main/res/values/strings.xml";
export const IOS_PBXPROJ = "ios/App/App.xcodeproj/project.pbxproj";
export const IOS_INFO_PLIST = "ios/App/App/Info.plist";

/**
 * Resolve the root Meridian version the native builds stamp.
 *
 * The same contract the Android Gradle block and the iOS stamp phase enforce
 * at build time, applied at test time: the root `package.json` two directories
 * above the wrapper is the only version source
 * (`docs/process/versioning-strategy.md`), and a manifest this function cannot
 * accept is a release neither native project could stamp.
 */
export function resolveRootMobileVersion(appDir: string, readFile: ReadFile = readFileSync): string {
  const rootPackageJsonPath = resolve(appDir, "..", "..", "package.json");

  let parsed: { name?: unknown; version?: unknown };
  try {
    parsed = JSON.parse(readFile(rootPackageJsonPath, "utf8") as string) as {
      name?: unknown;
      version?: unknown;
    };
  } catch (error) {
    throw new Error(
      `Cannot read the root package.json at ${rootPackageJsonPath}; ` +
        `the mobile package version comes from it and nowhere else: ${String(error)}`,
    );
  }

  if (parsed.name !== ROOT_PACKAGE_NAME) {
    throw new Error(
      `${rootPackageJsonPath} is not the Meridian root package; ` +
        "refusing to stamp the mobile application from it.",
    );
  }

  const version = parsed.version;
  if (typeof version !== "string" || !NUMERIC_VERSION.test(version)) {
    throw new Error(
      `The root package.json version must be numeric major.minor.patch, received: ${String(version)}`,
    );
  }

  return version;
}

/**
 * Pack a numeric major.minor.patch version the way both native projects do:
 * `major * 1_000_000 + minor * 1_000 + patch`. Android uses it as
 * `versionCode`, iOS as `CFBundleVersion`; both stores require the value to
 * only ever increase, which holds as long as minor and patch stay below 1000.
 */
export function packedVersionCode(version: string): number {
  if (!NUMERIC_VERSION.test(version)) {
    throw new Error(`Expected numeric major.minor.patch, received: ${version}`);
  }
  const [major, minor, patch] = version.split(".").map(Number);
  if (minor > 999 || patch > 999) {
    throw new Error(
      `minor and patch must stay below 1000 to pack a monotonic version code, received: ${version}`,
    );
  }
  return major * 1_000_000 + minor * 1_000 + patch;
}

function read(appDir: string, relativePath: string, readFile: ReadFile): string {
  return readFile(resolve(appDir, relativePath), "utf8") as string;
}

/** The `<uses-permission>` names the Android manifest declares, in order. */
export function androidManifestPermissions(
  appDir: string,
  readFile: ReadFile = readFileSync,
): string[] {
  const manifest = read(appDir, ANDROID_MANIFEST, readFile);
  return [...manifest.matchAll(/<uses-permission\s+android:name="([^"]+)"/g)].map(
    (match) => match[1],
  );
}

/** The `<uses-feature>` declarations in the Android manifest. */
export function androidManifestFeatures(
  appDir: string,
  readFile: ReadFile = readFileSync,
): { name: string; required: string | null }[] {
  const manifest = read(appDir, ANDROID_MANIFEST, readFile);
  return [...manifest.matchAll(/<uses-feature\s+([^>]+?)\/>/g)].map((match) => {
    const attributes = match[1];
    const name = /android:name="([^"]+)"/.exec(attributes)?.[1] ?? "";
    const required = /android:required="([^"]+)"/.exec(attributes)?.[1] ?? null;
    return { name, required };
  });
}

/** A named `<string>` resource from the Android strings.xml. */
export function androidStringResource(
  appDir: string,
  name: string,
  readFile: ReadFile = readFileSync,
): string | null {
  const strings = read(appDir, ANDROID_STRINGS, readFile);
  const match = new RegExp(`<string name="${name}">([^<]*)</string>`).exec(strings);
  return match ? match[1] : null;
}

/** The Android application module's Gradle build script, verbatim. */
export function androidAppGradle(appDir: string, readFile: ReadFile = readFileSync): string {
  return read(appDir, ANDROID_APP_GRADLE, readFile);
}

/** The iOS Xcode project file, verbatim. */
export function iosPbxproj(appDir: string, readFile: ReadFile = readFileSync): string {
  return read(appDir, IOS_PBXPROJ, readFile);
}

/** The iOS Info.plist, verbatim. */
export function iosInfoPlist(appDir: string, readFile: ReadFile = readFileSync): string {
  return read(appDir, IOS_INFO_PLIST, readFile);
}

/** A top-level `<key>` string value from the iOS Info.plist. */
export function iosInfoPlistString(
  appDir: string,
  key: string,
  readFile: ReadFile = readFileSync,
): string | null {
  const plist = iosInfoPlist(appDir, readFile);
  const match = new RegExp(`<key>${key}</key>\\s*<string>([^<]*)</string>`).exec(plist);
  return match ? match[1] : null;
}

/** Every usage-description key the iOS Info.plist declares. */
export function iosUsageDescriptionKeys(
  appDir: string,
  readFile: ReadFile = readFileSync,
): string[] {
  const plist = iosInfoPlist(appDir, readFile);
  return [...plist.matchAll(/<key>(NS[A-Za-z]+UsageDescription)<\/key>/g)].map(
    (match) => match[1],
  );
}

/**
 * The shell script of the iOS "Stamp Meridian Version" build phase, unescaped
 * from the pbxproj string literal, or null when the phase is missing.
 */
export function iosVersionStampScript(
  appDir: string,
  readFile: ReadFile = readFileSync,
): string | null {
  const pbx = iosPbxproj(appDir, readFile);
  const phase = /name = "Stamp Meridian Version";[\s\S]*?shellScript = "((?:[^"\\]|\\.)*)";/.exec(
    pbx,
  );
  if (!phase) return null;
  return phase[1]
    .replace(/\\n/g, "\n")
    .replace(/\\t/g, "\t")
    .replace(/\\"/g, '"')
    .replace(/\\\\/g, "\\");
}
