/**
 * Config tests over the committed native platform projects (M19.21).
 *
 * Both native projects must declare the shared application id and product
 * name from `capacitor.config.ts`, derive their version from the root
 * `package.json` rather than committing one, declare exactly the native
 * permissions the code-defined inventory in `nativePermissions.ts` explains,
 * and package `apps/client/dist/field` and nothing else.
 */
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

import capacitorConfig from "../capacitor.config";
import { ANDROID_PERMISSIONS, IOS_USAGE_DESCRIPTIONS } from "./nativePermissions";
import {
  androidAppGradle,
  androidManifestFeatures,
  androidManifestPermissions,
  androidStringResource,
  iosInfoPlist,
  iosInfoPlistString,
  iosPbxproj,
  iosUsageDescriptionKeys,
  iosVersionStampScript,
  packedVersionCode,
  resolveRootMobileVersion,
} from "./nativeProjects";

const appDir = resolve(__dirname, "..");

describe("shared application identity", () => {
  it("declares the shared application id in the Android project", () => {
    const gradle = androidAppGradle(appDir);
    expect(gradle).toContain(`applicationId "${capacitorConfig.appId}"`);
    expect(gradle).toContain(`namespace = "${capacitorConfig.appId}"`);
  });

  it("declares the shared application id in the iOS project", () => {
    const pbx = iosPbxproj(appDir);
    const identifiers = [...pbx.matchAll(/PRODUCT_BUNDLE_IDENTIFIER = ([^;]+);/g)].map(
      (match) => match[1],
    );
    expect(identifiers.length).toBeGreaterThan(0);
    for (const identifier of identifiers) {
      expect(identifier).toBe(capacitorConfig.appId);
    }
  });

  it("names the Android launcher entry Meridian Field", () => {
    expect(androidStringResource(appDir, "app_name")).toBe(capacitorConfig.appName);
    expect(androidStringResource(appDir, "title_activity_main")).toBe(capacitorConfig.appName);
    expect(androidStringResource(appDir, "package_name")).toBe(capacitorConfig.appId);
  });

  it("names the iOS application Meridian Field", () => {
    expect(iosInfoPlistString(appDir, "CFBundleDisplayName")).toBe(capacitorConfig.appName);
  });
});

describe("root-derived version", () => {
  it("resolves a stampable version from the root package.json", () => {
    // The same contract both native builds enforce: the root manifest must be
    // the Meridian root package with a numeric major.minor.patch version. A
    // root manifest this rejects is a release neither platform could build.
    const version = resolveRootMobileVersion(appDir);
    expect(version).toMatch(/^\d+\.\d+\.\d+$/);
    expect(packedVersionCode(version)).toBeGreaterThan(0);
  });

  it("derives the Android version from the root package.json at build time", () => {
    const gradle = androidAppGradle(appDir);
    expect(gradle).toContain("rootProject.file('../../../package.json')");
    expect(gradle).toMatch(/versionCode meridianVersionCode/);
    expect(gradle).toMatch(/versionName meridianVersionName/);
    // Refuses to stamp from the wrong manifest or a malformed version.
    expect(gradle).toContain("parsed.name != 'meridian'");
    expect(gradle).toContain("\\d+\\.\\d+\\.\\d+");
  });

  it("declares no Android version of its own", () => {
    const gradle = androidAppGradle(appDir);
    expect(gradle).not.toMatch(/versionName\s+"/);
    expect(gradle).not.toMatch(/versionCode\s+\d/);
  });

  it("stamps the iOS version from the root package.json at build time", () => {
    const script = iosVersionStampScript(appDir);
    expect(script).not.toBeNull();
    expect(script).toContain('MANIFEST="${SRCROOT}/../../../../package.json"');
    expect(script).toContain('"name": "meridian"');
    expect(script).toContain("Set :CFBundleShortVersionString ${VERSION}");
    expect(script).toContain("Set :CFBundleVersion ${BUILD}");
    // A version the script cannot resolve fails the build instead of
    // emitting an archive stamped with the placeholder.
    expect(script).toContain("set -eu");
    expect(script).toContain("exit 1");

    const pbx = iosPbxproj(appDir);
    // The phase runs on every build of the App target, not just the first,
    // and it is attached to the target's build phases.
    expect(pbx).toContain('name = "Stamp Meridian Version";');
    expect(pbx).toMatch(/alwaysOutOfDate = 1;/);
    expect(pbx).toMatch(/buildPhases = \([^)]*Stamp Meridian Version[^)]*\);/);
  });

  it("declares only the 0.0.0 placeholder as the iOS project version", () => {
    // The wrapper must not declare a version of its own
    // (docs/process/versioning-strategy.md); every built product is stamped
    // by the build phase instead, so the committed project never carries the
    // root version and the version-bump workflow never regenerates it.
    const pbx = iosPbxproj(appDir);
    const marketing = [...pbx.matchAll(/MARKETING_VERSION = ([^;]+);/g)].map((match) => match[1]);
    expect(marketing.length).toBeGreaterThan(0);
    for (const value of marketing) {
      expect(value).toBe("0.0.0");
    }
    const current = [...pbx.matchAll(/CURRENT_PROJECT_VERSION = ([^;]+);/g)].map(
      (match) => match[1],
    );
    for (const value of current) {
      expect(value).toBe("0");
    }
    const plist = iosInfoPlist(appDir);
    expect(plist).toContain("<string>$(MARKETING_VERSION)</string>");
    expect(plist).toContain("<string>$(CURRENT_PROJECT_VERSION)</string>");
  });

  it("packs major.minor.patch monotonically and refuses overflow", () => {
    expect(packedVersionCode("0.0.150")).toBe(150);
    expect(packedVersionCode("1.2.3")).toBe(1_002_003);
    expect(() => packedVersionCode("1.2.1000")).toThrow(/below 1000/);
    expect(() => packedVersionCode("1.2")).toThrow(/major\.minor\.patch/);
  });
});

describe("native permission inventory", () => {
  it("declares exactly the inventoried Android permissions", () => {
    // An unexplained permission — declared in the manifest without an
    // inventory entry naming the surface that asks for it — fails the build,
    // and so does an inventoried permission the manifest dropped.
    const declared = androidManifestPermissions(appDir);
    const inventoried = ANDROID_PERMISSIONS.map((entry) => entry.permission);
    expect([...declared].sort()).toEqual([...inventoried].sort());
  });

  it("declares exactly the inventoried iOS usage descriptions", () => {
    const declared = iosUsageDescriptionKeys(appDir);
    const inventoried = IOS_USAGE_DESCRIPTIONS.map((entry) => entry.permission);
    expect([...declared].sort()).toEqual([...inventoried].sort());
  });

  it("explains every inventory entry with a surface and a reason", () => {
    for (const entry of [...ANDROID_PERMISSIONS, ...IOS_USAGE_DESCRIPTIONS]) {
      expect(entry.surface).not.toHaveLength(0);
      expect(entry.reason).not.toHaveLength(0);
    }
  });

  it("keeps the camera optional so camera-less devices can install", () => {
    // The M18.61 scan path always offers the typed short-code fallback, so
    // the camera is requested, never required.
    const features = androidManifestFeatures(appDir);
    expect(features).toContainEqual({ name: "android.hardware.camera", required: "false" });
  });
});

describe("packaged client artifact", () => {
  it("references the Meridian Field client build and no other UI mode's", () => {
    expect(capacitorConfig.webDir).toBe("../client/dist/field");
  });

  it("keeps the copied web assets out of the repository", () => {
    // The native projects reference `apps/client/dist/field` through the
    // Capacitor webDir; the copies `cap sync` places inside each platform are
    // build artifacts. Committing them would freeze one build's bundle — and
    // its baked-in version — into the repository, so both platform gitignores
    // must keep them out.
    const androidIgnore = readFileSync(resolve(appDir, "android", ".gitignore"), "utf8");
    expect(androidIgnore).toContain("app/src/main/assets/public");
    const iosIgnore = readFileSync(resolve(appDir, "ios", ".gitignore"), "utf8");
    expect(iosIgnore).toContain("App/App/public");
  });
});

describe("generated identity assets", () => {
  it("commits an opaque 1024px iOS marketing icon", () => {
    // App Store validation rejects a marketing icon with an alpha channel;
    // scripts/generate-native-assets.mjs strips it, and this guards the next
    // regeneration. PNG IHDR: width at byte 16, height at 20, color type at
    // 25 (2 = truecolor without alpha).
    const icon = readFileSync(
      resolve(appDir, "ios", "App", "App", "Assets.xcassets", "AppIcon.appiconset", "AppIcon-512@2x.png"),
    );
    expect(icon.readUInt32BE(16)).toBe(1024);
    expect(icon.readUInt32BE(20)).toBe(1024);
    expect(icon.readUInt8(25)).toBe(2);
  });

  it("commits every Android launcher density and splash drawable", () => {
    const resDir = resolve(appDir, "android", "app", "src", "main", "res");
    for (const density of ["mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"]) {
      for (const name of ["ic_launcher.png", "ic_launcher_round.png", "ic_launcher_foreground.png"]) {
        expect(existsSync(resolve(resDir, `mipmap-${density}`, name)), `mipmap-${density}/${name}`).toBe(true);
      }
      for (const orientation of ["land", "port"]) {
        expect(
          existsSync(resolve(resDir, `drawable-${orientation}-${density}`, "splash.png")),
          `drawable-${orientation}-${density}/splash.png`,
        ).toBe(true);
      }
    }
    expect(existsSync(resolve(resDir, "drawable", "splash.png"))).toBe(true);
  });
});
