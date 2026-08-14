import { readFileSync } from "node:fs";
import { fileURLToPath, URL } from "node:url";

import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

const packageJson = JSON.parse(
  readFileSync(new URL("../../package.json", import.meta.url), "utf8"),
) as { version: string };

interface ClientBuildProfile {
  readonly deploymentTarget: "server" | "mobile" | "desktop";
  readonly uiMode: "admin" | "field" | "kiosk";
  readonly outDir: string;
}

const BUILD_PROFILES: Record<string, ClientBuildProfile> = {
  "meridian-admin": {
    deploymentTarget: "server",
    uiMode: "admin",
    outDir: "dist/admin",
  },
  "meridian-field": {
    deploymentTarget: "mobile",
    uiMode: "field",
    outDir: "dist/field",
  },
  "meridian-kiosk": {
    deploymentTarget: "desktop",
    uiMode: "kiosk",
    outDir: "dist/kiosk",
  },
};

function resolveBuildProfile(mode: string): ClientBuildProfile {
  return BUILD_PROFILES[mode] ?? BUILD_PROFILES["meridian-admin"];
}

/*
 * A built client is configured by the environment its build ran in, and by
 * nothing on the developer's disk.
 *
 * `dev:field` and `build:field` are the same Vite mode, so without this the
 * `.env.meridian-field.local` that `env:local` writes for the dev server is
 * also loaded into the production build and compiled into the bundle. For the
 * packaged Field app that is not a preference but a defect: the baked-in
 * `VITE_MERIDIAN_API_BASE_URL` becomes `nodeConnection.ts`'s `build` tier,
 * which outranks `convention`, so a phone that should assume
 * `meridian.home.arpa` (technical spec 8.4) assumes the developer's laptop
 * instead — an address no phone on the event network answers.
 *
 * Pointing builds at an env directory of their own leaves them exactly one
 * source: variables already exported when the build ran, which Vite gives
 * highest priority. That is how CI and a deployment bake in their own node
 * URL, and it is unavailable to a file somebody generated for local
 * development.
 */
const BUILD_ENV_DIR = fileURLToPath(new URL("./env/build", import.meta.url));

export default defineConfig(({ command, mode }) => {
  const profile = resolveBuildProfile(mode);

  return {
    envDir: command === "build" ? BUILD_ENV_DIR : undefined,
    plugins: [vue()],
    define: {
      __MERIDIAN_CLIENT_VERSION__: JSON.stringify(packageJson.version),
      __MERIDIAN_DEPLOYMENT_TARGET__: JSON.stringify(profile.deploymentTarget),
      __MERIDIAN_UI_MODE__: JSON.stringify(profile.uiMode),
    },
    build: {
      emptyOutDir: true,
      outDir: profile.outDir,
    },
    resolve: {
      alias: {
        "@": fileURLToPath(new URL("./src", import.meta.url)),
      },
    },
  };
});
