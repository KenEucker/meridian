/**
 * Code-defined mobile release signing credential inventory (M19.22).
 *
 * Technical spec 26.5 holds every signing credential — the Android upload
 * keystore, the Apple distribution certificate, and the provisioning
 * profile — outside the repository and supplies it to the build through the
 * environment. This inventory is the single statement of which environment
 * variables carry them, mirrored by the Android Gradle signing block
 * (`android/app/build.gradle`) and the iOS archive/export script
 * (`scripts/build-ios-release.sh`). The config tests hold both build
 * configurations against it, the same shape as `nativePermissions.ts` holds
 * the native manifests: a credential read that drifts from the inventory —
 * in either direction — fails the build.
 */

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

type ReadFile = typeof readFileSync;

/** One environment-supplied release signing credential. */
export interface ReleaseSigningCredential {
  /** The environment variable the build reads the credential from. */
  env: string;
  /** What the credential is, in release-runbook terms. */
  credential: string;
}

/**
 * The Android release variant signs from the upload keystore
 * (technical spec 26.5); these four variables are read by
 * `android/app/build.gradle` and demanded whenever a release task is
 * scheduled.
 */
export const ANDROID_RELEASE_CREDENTIALS: readonly ReleaseSigningCredential[] = [
  {
    env: "MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE",
    credential: "Filesystem path to the upload keystore, held outside the repository.",
  },
  {
    env: "MERIDIAN_ANDROID_UPLOAD_KEYSTORE_PASSWORD",
    credential: "Password of the upload keystore.",
  },
  {
    env: "MERIDIAN_ANDROID_UPLOAD_KEY_ALIAS",
    credential: "Alias of the upload signing key inside the keystore.",
  },
  {
    env: "MERIDIAN_ANDROID_UPLOAD_KEY_PASSWORD",
    credential: "Password of the upload signing key.",
  },
];

/**
 * The iOS archive/export configuration names the distribution certificate
 * and provisioning profile (technical spec 26.5); these three variables are
 * read by `scripts/build-ios-release.sh` before Xcode is invoked.
 */
export const IOS_RELEASE_CREDENTIALS: readonly ReleaseSigningCredential[] = [
  {
    env: "MERIDIAN_IOS_TEAM_ID",
    credential: "Apple Developer team identifier.",
  },
  {
    env: "MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE",
    credential: 'Distribution signing identity name (e.g. "Apple Distribution: ...").',
  },
  {
    env: "MERIDIAN_IOS_PROVISIONING_PROFILE",
    credential: "Name of the App Store provisioning profile for org.meridian.field.",
  },
];

/** Repository-relative location of the iOS release archive/export script. */
export const IOS_RELEASE_BUILD_SCRIPT = "scripts/build-ios-release.sh";

/** The iOS release archive/export script, verbatim. */
export function iosReleaseBuildScript(appDir: string, readFile: ReadFile = readFileSync): string {
  return readFile(resolve(appDir, IOS_RELEASE_BUILD_SCRIPT), "utf8") as string;
}

/**
 * File name patterns that identify committed signing key material: Android
 * keystores, PKCS #12 bundles, Apple certificates and provisioning profiles,
 * and bare key files. Matched case-insensitively against every committed
 * path; a single hit anywhere in the repository fails the build, because a
 * credential that reaches version control is published, not held
 * (technical spec 26.5).
 */
export const KEY_MATERIAL_FILE_PATTERN =
  /\.(keystore|jks|p12|pfx|mobileprovision|provisionprofile|cer|der|pem|key)$/i;

/** The committed paths that look like signing key material. */
export function committedKeyMaterial(committedPaths: readonly string[]): string[] {
  return committedPaths.filter((path) => KEY_MATERIAL_FILE_PATTERN.test(path));
}
