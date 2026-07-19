import { fileURLToPath, URL } from "node:url";

import { defineConfig } from "vitest/config";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
  plugins: [vue()],
  define: {
    __MERIDIAN_CLIENT_VERSION__: JSON.stringify("0.0.0-test"),
    __MERIDIAN_DEPLOYMENT_TARGET__: JSON.stringify("server"),
    __MERIDIAN_UI_MODE__: JSON.stringify("admin"),
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  test: {
    environment: "jsdom",
    globals: true,
    include: ["src/**/*.spec.ts"],
  },
});
