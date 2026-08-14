// @vitest-environment node
//
// What configures a built client (technical spec 8.4).
//
// `dev:field` and `build:field` are the same Vite mode, so the env files
// `corepack pnpm run env:local` writes for the dev server are visible to the
// production build too. For the packaged Field app that is a defect rather
// than a preference: a baked-in `VITE_MERIDIAN_API_BASE_URL` becomes the
// `build` tier in `nodeConnection.ts`, which outranks `convention`, so a
// phone assumes the developer's laptop instead of `meridian.home.arpa`.
//
// These hold the rule that prevents it — a build loads no env file, and takes
// its values from the environment it ran in — at the level where it is
// decided, so the next person to touch the Vite configuration finds out.

import { readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import viteConfig from "./vite.config";

type ConfigFactory = (context: {
  command: "build" | "serve";
  mode: string;
}) => { envDir?: string; build?: { outDir?: string }; define?: Record<string, string> };

const resolveConfig = viteConfig as unknown as ConfigFactory;

const buildEnvDir = fileURLToPath(new URL("./env/build", import.meta.url));

describe("what configures a built client", () => {
  it("loads no env file when building, for every UI mode", () => {
    for (const mode of ["meridian-admin", "meridian-field", "meridian-kiosk"]) {
      expect(resolveConfig({ command: "build", mode }).envDir).toBe(buildEnvDir);
    }
  });

  it("keeps the build env directory empty of env files", () => {
    // The directory is the mechanism: anything dropped here is compiled into
    // every packaged app, which is exactly what this guard exists to prevent.
    const envFiles = readdirSync(buildEnvDir).filter((entry) => entry.startsWith(".env"));
    expect(envFiles).toEqual([]);
  });

  it("still reads the developer's env files when serving", () => {
    // The dev server is the case those files are written for.
    expect(resolveConfig({ command: "serve", mode: "meridian-field" }).envDir).toBeUndefined();
  });

  it("builds each UI mode into its own directory", () => {
    // The profile lookup falls back to admin for an unknown mode, so a typo in
    // a build script would otherwise emit the wrong client under the right name.
    expect(resolveConfig({ command: "build", mode: "meridian-field" }).build?.outDir).toBe("dist/field");
    expect(resolveConfig({ command: "build", mode: "meridian-kiosk" }).build?.outDir).toBe("dist/kiosk");
    expect(resolveConfig({ command: "build", mode: "meridian-admin" }).build?.outDir).toBe("dist/admin");
  });

  it("stamps the deployment target each UI mode is packaged for", () => {
    const define = resolveConfig({ command: "build", mode: "meridian-field" }).define ?? {};
    expect(define.__MERIDIAN_DEPLOYMENT_TARGET__).toBe(JSON.stringify("mobile"));
    expect(define.__MERIDIAN_UI_MODE__).toBe(JSON.stringify("field"));
  });
});
