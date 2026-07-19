import { readFileSync } from "node:fs";
import { fileURLToPath, URL } from "node:url";

import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

const packageJson = JSON.parse(
  readFileSync(new URL("./package.json", import.meta.url), "utf8"),
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

export default defineConfig(({ mode }) => {
  const profile = resolveBuildProfile(mode);

  return {
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
