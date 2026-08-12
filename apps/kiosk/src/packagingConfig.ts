/**
 * Desktop installer packaging configuration for Meridian Kiosk (M19.20).
 *
 * Technical spec 26.6 builds the Windows, macOS, and Linux installers from one
 * packaging configuration so the three cannot drift into three different
 * applications; this module is that one configuration. `electron-builder`
 * consumes it through `electron-builder.config.cjs`, which loads the compiled
 * form of this file. It is a pure builder over injectable file reading so the
 * config tests can assert what a build would do without running one.
 *
 * The wrapper packages the already built `apps/client/dist/kiosk` artifact and
 * no other UI mode's build (technical spec 26.4): the Admin build ships inside
 * the server image and the Field build inside the mobile package, and packaging
 * a second mode here would break the compile-time UI mode rule of section 3.2.
 *
 * The package version is the root `package.json` version, injected through
 * `extraMetadata` because `docs/process/versioning-strategy.md` forbids the
 * wrapper manifest from declaring a version of its own. A missing or malformed
 * root version fails the packaging run: an installer stamped "unknown" looks
 * distributable and is exactly the artifact technical spec 26.5's signing
 * policy teaches this repository never to emit.
 *
 * Alpha 1 desktop artifacts are unsigned on purpose (technical spec 26.6);
 * code signing and macOS notarization are beta scope, and the install
 * documentation states the first-run trust warning instead of hiding it.
 */

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/** Application identifier shared by every desktop target (technical spec 26.4). */
export const KIOSK_APP_ID = "org.meridian.kiosk";

/** Installed product name shown by the operating system. */
export const KIOSK_PRODUCT_NAME = "Meridian Kiosk";

/** The one icon asset every target's icon is generated from. */
export const KIOSK_ICON = "assets/icon.png";

/** Where the packaged Kiosk client build lives inside the installed resources. */
export const PACKAGED_CLIENT_RESOURCE_DIR = "client/kiosk";

/** The already built client artifact the wrapper packages (technical spec 26.4). */
export const KIOSK_CLIENT_DIST = "../client/dist/kiosk";

/** Directory electron-builder writes installers into. Gitignored; never committed. */
export const PACKAGING_OUTPUT_DIR = "release";

type ReadFile = typeof readFileSync;

const NUMERIC_VERSION = /^\d+\.\d+\.\d+$/;
const ROOT_PACKAGE_NAME = "meridian";

/**
 * Resolve the root Meridian version the installers are stamped with.
 *
 * Reads the repository root `package.json` two directories above the wrapper,
 * which is where the packaging configuration always runs from — unlike the
 * runtime resolution in `config.ts`, packaging happens in a checkout, so a
 * walk upward would only obscure a broken tree. Throws instead of defaulting:
 * a version this function cannot resolve is a build that must not produce an
 * artifact (versioning strategy, Release Packaging).
 */
export function resolveRootPackagingVersion(
  appDir: string,
  readFile: ReadFile = readFileSync,
): string {
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
        `the desktop package version comes from it and nowhere else: ${String(error)}`,
    );
  }

  if (parsed.name !== ROOT_PACKAGE_NAME) {
    throw new Error(
      `${rootPackageJsonPath} is not the Meridian root package; ` +
        "refusing to stamp the desktop installer from it.",
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
 * Build the electron-builder configuration for the Meridian Kiosk installers.
 *
 * Typed structurally rather than against electron-builder's own types so the
 * pure module stays importable by tests without loading electron-builder.
 */
export function buildDesktopPackagingConfig(
  appDir: string,
  readFile: ReadFile = readFileSync,
): {
  appId: string;
  productName: string;
  extraMetadata: { version: string };
  directories: { output: string; buildResources: string };
  files: string[];
  extraResources: { from: string; to: string }[];
  win: { target: string[]; icon: string };
  mac: { target: string[]; icon: string; category: string; identity: null };
  linux: { target: string[]; icon: string; category: string; executableName: string };
  artifactName: string;
  npmRebuild: boolean;
  forceCodeSigning: boolean;
} {
  return {
    appId: KIOSK_APP_ID,
    productName: KIOSK_PRODUCT_NAME,
    // The wrapper manifest declares no version of its own; the root version is
    // injected into the packaged app's metadata here, which is also what
    // `app.getVersion()` reports to the installed wrapper at runtime.
    extraMetadata: { version: resolveRootPackagingVersion(appDir, readFile) },
    directories: {
      output: PACKAGING_OUTPUT_DIR,
      buildResources: "assets",
    },
    // The compiled main-process tree and the icon the window loads at runtime.
    // No node_modules beyond production dependencies (the wrapper has none),
    // and never a client build through `files` — the client artifact travels
    // as an extra resource so the static server reads it from disk, not asar.
    files: ["dist/**/*", "assets/icon.png", "package.json"],
    extraResources: [
      {
        from: KIOSK_CLIENT_DIST,
        to: PACKAGED_CLIENT_RESOURCE_DIR,
      },
    ],
    win: { target: ["nsis"], icon: KIOSK_ICON },
    // `identity: null` states the Alpha 1 unsigned policy explicitly rather
    // than depending on the build machine having no signing identity to find.
    mac: {
      target: ["dmg"],
      icon: KIOSK_ICON,
      category: "public.app-category.utilities",
      identity: null,
    },
    // Linux derives the executable name from the package name by default, and
    // the scoped `@meridian/kiosk` cannot be used in file paths.
    linux: { target: ["AppImage"], icon: KIOSK_ICON, category: "Utility", executableName: "meridian-kiosk" },
    artifactName: "meridian-kiosk-${version}-${os}-${arch}.${ext}",
    // The wrapper has no native production dependencies to rebuild.
    npmRebuild: false,
    forceCodeSigning: false,
  };
}
