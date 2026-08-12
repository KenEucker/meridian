/**
 * Build config tests over the mobile release signing configuration (M19.22).
 *
 * Technical spec 26.5: every signing credential resolves from the
 * environment, none is defaulted in the repository, no key material is
 * committed, and a release build that cannot find a credential fails rather
 * than falling back to debug signing or emitting an unsigned artifact that
 * looks distributable.
 */
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

import capacitorConfig from "../capacitor.config";
import { androidAppGradle } from "./nativeProjects";
import {
  ANDROID_RELEASE_CREDENTIALS,
  committedKeyMaterial,
  IOS_RELEASE_CREDENTIALS,
  iosReleaseBuildScript,
} from "./releaseSigning";

const appDir = resolve(__dirname, "..");
const repoRoot = resolve(appDir, "..", "..");

function trackedPaths(...pathspec: string[]): string[] {
  return execFileSync("git", ["ls-files", "-z", "--", ...pathspec], {
    cwd: repoRoot,
    encoding: "utf8",
    maxBuffer: 64 * 1024 * 1024,
  })
    .split("\0")
    .filter((path) => path.length > 0);
}

describe("credentials resolve from the environment", () => {
  it("reads every inventoried Android credential from the environment in the Gradle build", () => {
    const gradle = androidAppGradle(appDir);
    for (const { env } of ANDROID_RELEASE_CREDENTIALS) {
      expect(gradle, env).toContain(`'${env}'`);
    }
  });

  it("declares exactly the inventoried Android credentials in the Gradle build", () => {
    // The Gradle-side reads and the code-defined inventory must agree in
    // both directions: a credential the build reads without an inventory
    // entry is as much drift as an inventoried credential the build stopped
    // reading.
    const gradle = androidAppGradle(appDir);
    const declared = [...gradle.matchAll(/MERIDIAN_ANDROID_[A-Z_]+/g)].map((match) => match[0]);
    const inventoried = ANDROID_RELEASE_CREDENTIALS.map((entry) => entry.env);
    expect([...new Set(declared)].sort()).toEqual([...inventoried].sort());
  });

  it("reads every inventoried iOS credential from the environment in the release script", () => {
    const script = iosReleaseBuildScript(appDir);
    for (const { env } of IOS_RELEASE_CREDENTIALS) {
      expect(script, env).toContain(env);
    }
  });

  it("declares exactly the inventoried iOS credentials in the release script", () => {
    const script = iosReleaseBuildScript(appDir);
    const declared = [...script.matchAll(/MERIDIAN_IOS_[A-Z_]+/g)].map((match) => match[0]);
    const inventoried = IOS_RELEASE_CREDENTIALS.map((entry) => entry.env);
    expect([...new Set(declared)].sort()).toEqual([...inventoried].sort());
  });

  it("wires the iOS credentials into manual signing for both archive and export", () => {
    // The archive names the team, certificate, and profile explicitly, and
    // the generated ExportOptions.plist repeats them for the export step —
    // manual signing throughout, so no automatic identity is ever selected.
    const script = iosReleaseBuildScript(appDir);
    expect(script).toContain("CODE_SIGN_STYLE=Manual");
    expect(script).toContain('DEVELOPMENT_TEAM="$MERIDIAN_IOS_TEAM_ID"');
    expect(script).toContain('CODE_SIGN_IDENTITY="$MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE"');
    expect(script).toContain('PROVISIONING_PROFILE_SPECIFIER="$MERIDIAN_IOS_PROVISIONING_PROFILE"');
    expect(script).toContain("<string>manual</string>");
    expect(script).toContain("xcodebuild -exportArchive");
    // The provisioning profile is bound to the shared application id, not to
    // a hardcoded stray.
    expect(script).toContain(`BUNDLE_ID="${capacitorConfig.appId}"`);
  });

  it("explains every inventory entry", () => {
    for (const entry of [...ANDROID_RELEASE_CREDENTIALS, ...IOS_RELEASE_CREDENTIALS]) {
      expect(entry.env).toMatch(/^MERIDIAN_(ANDROID|IOS)_[A-Z_]+$/);
      expect(entry.credential).not.toHaveLength(0);
    }
  });
});

describe("no credential is defaulted in-repo", () => {
  it("gives no Gradle environment read a fallback value", () => {
    // `System.getenv(...) ?: '...'` would put a credential default in the
    // repository; a missing variable must stay missing so the release guard
    // can refuse the build.
    const gradle = androidAppGradle(appDir);
    expect(gradle).not.toMatch(/System\.getenv\([^)]*\)\s*\?:/);
  });

  it("gives no script environment read a default expansion", () => {
    // `${MERIDIAN_...:-default}` or `${MERIDIAN_...=default}` would default
    // a credential in-repo.
    const script = iosReleaseBuildScript(appDir);
    expect(script).not.toMatch(/\$\{MERIDIAN_[A-Z_]+:?[-=]/);
  });

  it("commits no keystore path, password, identity, or profile value", () => {
    // The build configurations may name the environment variables, never
    // their values. A literal that satisfies a credential read would turn
    // the repository into the credential store 26.5 forbids.
    const gradle = androidAppGradle(appDir);
    const script = iosReleaseBuildScript(appDir);
    for (const text of [gradle, script]) {
      expect(text).not.toMatch(/storeFile\s+file\(['"]/);
      expect(text).not.toMatch(/storePassword\s+['"]/);
      expect(text).not.toMatch(/keyPassword\s+['"]/);
      expect(text).not.toMatch(/Apple Distribution: [A-Za-z]/);
    }
  });
});

describe("no key material is committed", () => {
  it("finds no keystore, certificate, profile, or key file among tracked paths", () => {
    const tracked = trackedPaths();
    expect(tracked.length).toBeGreaterThan(0);
    expect(committedKeyMaterial(tracked)).toEqual([]);
  });

  it("finds no embedded private key block in the mobile wrapper", () => {
    // The marker is assembled at runtime so this spec's own source never
    // contains the contiguous string it hunts for.
    const privateKeyBlockMarker = ["PRIVATE", "KEY-----"].join(" ");
    const textFiles = trackedPaths("apps/mobile").filter(
      (path) => !/\.(png|webp|ico|jar)$/i.test(path),
    );
    expect(textFiles.length).toBeGreaterThan(0);
    for (const path of textFiles) {
      const content = readFileSync(resolve(repoRoot, path), "utf8");
      expect(content, path).not.toContain(privateKeyBlockMarker);
    }
  });

  it("recognizes every key material shape the release path handles", () => {
    expect(
      committedKeyMaterial([
        "upload.keystore",
        "upload.jks",
        "distribution.p12",
        "distribution.cer",
        "MeridianField.mobileprovision",
        "MeridianField.provisionprofile",
        "signing.pem",
        "signing.key",
        "apps/mobile/README.md",
        "keystoreNotes.md",
      ]),
    ).toEqual([
      "upload.keystore",
      "upload.jks",
      "distribution.p12",
      "distribution.cer",
      "MeridianField.mobileprovision",
      "MeridianField.provisionprofile",
      "signing.pem",
      "signing.key",
    ]);
  });

  it("keeps local key material out of the native project trees", () => {
    // Belt beside the suspenders: even a keystore dropped locally next to
    // the Gradle build must never become committable.
    const android = readFileSync(resolve(appDir, "android", ".gitignore"), "utf8");
    expect(android).toContain("*.jks");
    expect(android).toContain("*.keystore");
    const ios = readFileSync(resolve(appDir, "ios", ".gitignore"), "utf8");
    expect(ios).toContain("*.p12");
    expect(ios).toContain("*.mobileprovision");
    expect(ios).toContain("*.cer");
  });
});

describe("a missing credential fails the release build", () => {
  it("guards every scheduled Android release task behind the credential check", () => {
    const gradle = androidAppGradle(appDir);
    // The guard fires when any release-variant task is scheduled...
    expect(gradle).toContain("gradle.taskGraph.whenReady");
    expect(gradle).toMatch(/task\.name ==~ \/\(\?i\)\.\*release\.\*\//);
    // ...and refuses the build by exception, naming the missing variables.
    expect(gradle).toMatch(/GradleException\(\s*\n?\s*"Missing Android release signing credentials/);
    // A keystore path that resolves but points nowhere is refused too.
    expect(gradle).toContain("refusing to build a release without the upload keystore");
  });

  it("never signs the release variant with the debug signing config", () => {
    const gradle = androidAppGradle(appDir);
    // The release signing config is attached only when every credential is
    // present, and nothing ever points the release variant at debug keys.
    expect(gradle).not.toMatch(/signingConfig\s+signingConfigs\.debug/);
    expect(gradle).toMatch(
      /release \{\s*\n\s*if \(meridianMissingReleaseCredentials\.isEmpty\(\)\) \{\s*\n\s*signingConfig signingConfigs\.release/,
    );
  });

  it("fails the iOS script before invoking Xcode when a credential is missing", () => {
    const script = iosReleaseBuildScript(appDir);
    // set -eu: an unset variable or failed step aborts; the explicit check
    // exits 1 with the missing names before any xcodebuild call.
    expect(script).toContain("set -eu");
    const checkIndex = script.indexOf("Missing iOS release signing credentials");
    const xcodebuildIndex = script.indexOf("xcodebuild archive");
    expect(checkIndex).toBeGreaterThan(-1);
    expect(xcodebuildIndex).toBeGreaterThan(checkIndex);
    expect(script).toMatch(/Missing iOS release signing credentials[\s\S]{0,400}exit 1/);
  });

  it("keeps the generated export options out of the repository", () => {
    // ExportOptions.plist is written into ios/App/build at build time and
    // carries credential-derived values; the build directory is gitignored.
    const script = iosReleaseBuildScript(appDir);
    expect(script).toContain('EXPORT_OPTIONS="$BUILD_DIR/ExportOptions.plist"');
    expect(script).toContain('BUILD_DIR="$APP_DIR/ios/App/build"');
    const iosIgnore = readFileSync(resolve(appDir, "ios", ".gitignore"), "utf8");
    expect(iosIgnore).toContain("App/build");
  });
});
