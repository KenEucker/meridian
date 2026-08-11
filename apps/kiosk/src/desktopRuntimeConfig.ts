/**
 * What the desktop wrapper tells the shared client about itself, before any
 * page script runs (M18.32, M19.1).
 *
 * The client reads `window.__MERIDIAN_RUNTIME_CONFIG__` for the handful of
 * facts a build cannot know. Two of them are the wrapper's to answer: which
 * trusted shared workstation this machine is, and which version of the wrapper
 * is serving the bundle. The second one matters because the wrapper and the
 * client it serves are separate artifacts — an installed desktop app that was
 * never updated looks identical, from inside, to one that was.
 *
 * The two arrive by different routes on purpose. The workstation identifier is
 * a deployment setting and comes from the environment, so a scripted install
 * can set it. The version is resolved by the main process at startup from the
 * root manifest, so it is handed to the preload through Electron's documented
 * `additionalArguments` channel rather than by mutating the environment of a
 * process that has already been spawned.
 *
 * Neither is a credential. The workstation identifier grants nothing on its own
 * (AUTH-030) and a version number is printed in the health panel already, which
 * is why both can be handed to the renderer at all.
 *
 * Pure so it can be unit tested without an Electron runtime; `preload.ts` is
 * the thin bridge that hands the result across.
 */

/** Prefix of the renderer argument carrying the wrapper's own version. */
export const DESKTOP_APP_VERSION_ARGUMENT_PREFIX = "--meridian-desktop-app-version=";

export interface DesktopRuntimeConfig {
  readonly sharedWorkstationId?: string;
  readonly desktopAppVersion?: string;
}

/** Format the version argument the main process passes to the preload. */
export function desktopAppVersionArgument(version: string): string {
  return `${DESKTOP_APP_VERSION_ARGUMENT_PREFIX}${version}`;
}

/**
 * Build the runtime config to expose, or `null` when there is nothing to say.
 *
 * Null rather than an empty object so a wrapper with nothing to contribute
 * leaves the client exactly as it found it: the client falls back to whatever
 * the machine itself is configured with, which is what an unpackaged
 * development run should look like.
 */
export function buildDesktopRuntimeConfig(
  input: {
    env?: Record<string, string | undefined>;
    argv?: readonly string[];
  } = {},
): DesktopRuntimeConfig | null {
  const sharedWorkstationId = input.env?.MERIDIAN_SHARED_WORKSTATION_ID?.trim();
  const desktopAppVersion = readDesktopAppVersion(input.argv ?? []);

  const config: DesktopRuntimeConfig = {
    ...(sharedWorkstationId ? { sharedWorkstationId } : {}),
    ...(desktopAppVersion === null ? {} : { desktopAppVersion }),
  };

  return Object.keys(config).length === 0 ? null : config;
}

function readDesktopAppVersion(argv: readonly string[]): string | null {
  const argument = argv.find((value) =>
    value.startsWith(DESKTOP_APP_VERSION_ARGUMENT_PREFIX),
  );

  if (argument === undefined) {
    return null;
  }

  const version = argument.slice(DESKTOP_APP_VERSION_ARGUMENT_PREFIX.length).trim();

  return version === "" ? null : version;
}
