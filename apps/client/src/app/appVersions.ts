/**
 * What this Meridian app reports about its own build (technical spec 26.3).
 *
 * One screen answers "what am I running, and what is the node running" for
 * every artifact, because a version question asked on site is asked once and
 * about the whole stack: the bundle in front of the person, the wrapper it is
 * installed as, and the server it is talking to. Splitting those across three
 * places is how a support conversation turns into three of them.
 *
 * The functions that build the rows are pure so the composition can be unit
 * tested without a browser, a wrapper, or a node; the two readers below are the
 * only parts that touch `window`.
 */

import type { MeridianAppConfig } from "@/app/appConfig";

/** One labelled version fact, rendered in the order it is returned. */
export interface VersionRow {
  readonly label: string;
  readonly value: string;
}

export interface VersionSources {
  /** Which artifact this is, and therefore what to call it.  */
  readonly config: MeridianAppConfig;
  /** The version this bundle was built from (root `package.json`). */
  readonly clientVersion: string;
  /**
   * The Electron wrapper's own version, when the wrapper reported one.
   *
   * Null in a browser, which is the ordinary case for Admin and Field and the
   * development case for Kiosk. A wrapper version the client invented would be
   * worse than no row at all.
   */
  readonly desktopAppVersion: string | null;
  /**
   * The Capacitor platform this is installed on, when it is installed at all.
   * Null in a browser, including a phone browser pointed at the Field build.
   */
  readonly nativePlatform: string | null;
  /** From the node's health payload, or null when it did not answer. */
  readonly serverVersion: string | null;
  readonly configSchemaVersion: number | null;
}

/** Shown for a server-sourced version when the node did not answer. */
export const VERSION_UNAVAILABLE = "Unavailable";

/**
 * Build the version rows for this app and node.
 *
 * The mobile and desktop rows are present only when their wrapper is, rather
 * than rendered as "Unavailable". Absent means "not running inside that app",
 * which is a different statement from "the value could not be read", and the
 * two server rows are the ones that genuinely go unavailable.
 */
export function describeVersions(sources: VersionSources): VersionRow[] {
  const rows: VersionRow[] = [{ label: "App", value: sources.config.productName }];

  if (sources.nativePlatform !== null) {
    rows.push({
      label: "Mobile app version",
      value: `${sources.clientVersion} (${sources.nativePlatform})`,
    });
  }

  if (sources.desktopAppVersion !== null) {
    rows.push({ label: "Desktop app version", value: sources.desktopAppVersion });
  }

  rows.push(
    { label: "Client bundle version", value: sources.clientVersion },
    { label: "UI mode", value: sources.config.modeDisplayName },
    { label: "Deployment target", value: sources.config.deploymentTarget },
    {
      label: "Server version",
      value: sources.serverVersion ?? VERSION_UNAVAILABLE,
    },
    {
      label: "Config schema version",
      value:
        sources.configSchemaVersion === null
          ? VERSION_UNAVAILABLE
          : String(sources.configSchemaVersion),
    },
  );

  return rows;
}

/**
 * The desktop wrapper's version, as the wrapper itself stated it.
 *
 * The Electron preload puts it in the runtime config before any page script
 * runs (`apps/kiosk/src/desktopRuntimeConfig.ts`). It is read rather than
 * assumed equal to the bundle version: a packaged wrapper and the client it
 * serves are two artifacts, and the case worth catching is the one where they
 * disagree.
 */
export function readDesktopAppVersion(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  const version = window.__MERIDIAN_RUNTIME_CONFIG__?.desktopAppVersion?.trim();

  return version ? version : null;
}

/**
 * The native platform this is installed on, when Capacitor is running it.
 *
 * `web` is Capacitor's own answer for a browser and is treated as no platform:
 * the Field build opened in a phone browser is not the installed app, and
 * saying "mobile app version" about it would be a claim nobody could act on.
 */
export function readNativePlatform(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  const platform = window.Capacitor?.getPlatform?.();

  return typeof platform === "string" && platform !== "" && platform !== "web"
    ? platform
    : null;
}
