import { describe, expect, it } from "vitest";

import {
  DEFAULT_SERVER_URL,
  NODE_SETTINGS_FILE,
  readStoredServerUrl,
  resolveServerUrlSetting,
} from "./config";

/** A `readFileSync` stand-in backed by an in-memory map. */
function files(contents: Record<string, string>) {
  return ((path: string) => {
    const found = contents[String(path)];

    if (found === undefined) {
      throw Object.assign(new Error("ENOENT"), { code: "ENOENT" });
    }

    return found;
  }) as never;
}

const settingsPath = `/userData/${NODE_SETTINGS_FILE}`;

describe("readStoredServerUrl", () => {
  it("reads and normalizes the stored node", () => {
    const read = files({
      [settingsPath]: JSON.stringify({ serverUrl: "https://onsite.example.org/" }),
    });

    expect(readStoredServerUrl(settingsPath, read)).toBe("https://onsite.example.org/");
  });

  it("treats a missing file as no setting", () => {
    expect(readStoredServerUrl(settingsPath, files({}))).toBeNull();
  });

  it("treats a malformed file as no setting rather than failing to start", () => {
    // The wrapper's job is to open the UI. Refusing to start over a stray
    // comma would strand an operator with no way in and no way to fix it.
    const read = files({ [settingsPath]: "{ not json" });

    expect(readStoredServerUrl(settingsPath, read)).toBeNull();
  });

  it("treats an empty or absent serverUrl as no setting", () => {
    expect(
      readStoredServerUrl(settingsPath, files({ [settingsPath]: "{}" })),
    ).toBeNull();
    expect(
      readStoredServerUrl(
        settingsPath,
        files({ [settingsPath]: JSON.stringify({ serverUrl: "   " }) }),
      ),
    ).toBeNull();
  });

  it("treats an address the wrapper cannot use as no setting", () => {
    // Falling back is visible in the health panel, which an operator can act
    // on. Throwing here would be an app that does not open.
    const read = files({
      [settingsPath]: JSON.stringify({ serverUrl: "ftp://example.org" }),
    });

    expect(readStoredServerUrl(settingsPath, read)).toBeNull();
  });
});

describe("resolveServerUrlSetting", () => {
  it("prefers the environment so a scripted deployment keeps deciding", () => {
    const read = files({
      [settingsPath]: JSON.stringify({ serverUrl: "https://stored.example.org" }),
    });

    expect(
      resolveServerUrlSetting(
        { MERIDIAN_SERVER_URL: "https://env.example.org" },
        settingsPath,
        read,
      ),
    ).toMatchObject({ url: "https://env.example.org/", source: "environment" });
  });

  it("uses the stored node when the environment says nothing", () => {
    const read = files({
      [settingsPath]: JSON.stringify({ serverUrl: "https://stored.example.org" }),
    });

    expect(resolveServerUrlSetting({}, settingsPath, read)).toMatchObject({
      url: "https://stored.example.org/",
      source: "stored",
      settingsPath,
    });
  });

  it("falls back to the local development node with its source reported", () => {
    expect(resolveServerUrlSetting({}, settingsPath, files({}))).toMatchObject({
      url: DEFAULT_SERVER_URL,
      source: "default",
      settingsPath,
    });
  });

  it("works before Electron knows its data directory", () => {
    // The userData path is only available once the app is ready, so the
    // resolver has to answer without one.
    expect(resolveServerUrlSetting({}, null)).toMatchObject({
      url: DEFAULT_SERVER_URL,
      source: "default",
      settingsPath: null,
    });
  });
});
