#!/usr/bin/env node

import { spawnSync } from "node:child_process";
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const withServer = process.argv.includes("--with-server");

const serverEnvPath = resolve(repoRoot, "apps/server/.env");
const serverEnvExamplePath = resolve(repoRoot, "apps/server/.env.example");
const clientEnvDir = resolve(repoRoot, "apps/client");

const defaultApiBaseUrl = "http://127.0.0.1:8000";
const defaultViteUrl = "http://localhost:5173";
/*
 * Settings the shared-token middleware used, cleared out of an environment that
 * still carries them (M16.11).
 *
 * Nothing reads them any more, so leaving them would be harmless and confusing:
 * a developer reading their own `.env` would find a credential that looks live.
 */
const removedLocalFieldApiKeys = [
  "MERIDIAN_LOCAL_FIELD_API_ENABLED",
  "MERIDIAN_LOCAL_FIELD_API_TOKEN",
  "MERIDIAN_LOCAL_FIELD_API_USER_ID",
  "VITE_MERIDIAN_LOCAL_FIELD_API_TOKEN",
];
const clientEnvFiles = [
  ".env.development.local",
  ".env.meridian-admin.local",
  ".env.meridian-field.local",
  ".env.meridian-kiosk.local",
];

function main() {
  ensureServerEnvExists();

  const serverEnv = readEnvFile(serverEnvPath);
  const apiBaseUrl = normalizeLocalApiBaseUrl(valueOrDefault(serverEnv.values.APP_URL, defaultApiBaseUrl));

  // No API credential is configured anywhere here (M16.11). A developer signs in
  // to the client as the seeded fixture user and the node issues a token bound
  // to that browser's device, the same way a staff member's phone gets one.
  writeEnvFile(serverEnvPath, {
    APP_URL: apiBaseUrl,
    MERIDIAN_CLIENT_USE_DEV_SERVER: "true",
    MERIDIAN_CLIENT_DEV_SERVER_URL: defaultViteUrl,
    MERIDIAN_CORS_ALLOWED_ORIGINS: mergeCsv(serverEnv.values.MERIDIAN_CORS_ALLOWED_ORIGINS, [
      "http://127.0.0.1:5173",
      "http://localhost:5173",
    ]),
  }, removedLocalFieldApiKeys);

  for (const file of clientEnvFiles) {
    writeEnvFile(
      resolve(clientEnvDir, file),
      {
        VITE_MERIDIAN_API_BASE_URL: apiBaseUrl,
        VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION: "true",
      },
      removedLocalFieldApiKeys,
    );
  }

  if (withServer) {
    ensureAppKey();
    runArtisan("config:clear");
    runArtisan("migrate");
    runArtisan("meridian:seed-local-field-fixture");
  }
}

function ensureServerEnvExists() {
  if (existsSync(serverEnvPath)) {
    return;
  }

  copyFileSync(serverEnvExamplePath, serverEnvPath);
  console.log(`Created ${relative(serverEnvPath)} from ${relative(serverEnvExamplePath)}.`);
}

function readEnvFile(path) {
  if (!existsSync(path)) {
    return { lines: [], values: {} };
  }

  const lines = readFileSync(path, "utf8").split(/\r?\n/);
  const values = {};
  for (const line of lines) {
    const match = /^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/.exec(line);
    if (match) {
      values[match[1]] = stripQuotes(match[2]);
    }
  }

  return { lines, values };
}

function writeEnvFile(path, updates, removals = []) {
  const existing = readEnvFile(path);
  const remaining = new Map(Object.entries(updates));
  const dropped = new Set(removals);
  const nextLines = existing.lines.flatMap((line) => {
    const match = /^([A-Za-z_][A-Za-z0-9_]*)=/.exec(line);
    if (!match) {
      return [line];
    }

    if (dropped.has(match[1])) {
      return [];
    }

    if (!remaining.has(match[1])) {
      return [line];
    }

    const value = remaining.get(match[1]);
    remaining.delete(match[1]);
    return [`${match[1]}=${value}`];
  });

  if (nextLines.length > 0 && nextLines[nextLines.length - 1] !== "" && remaining.size > 0) {
    nextLines.push("");
  }

  for (const [key, value] of remaining) {
    nextLines.push(`${key}=${value}`);
  }

  const contents = `${nextLines.join("\n").replace(/\n+$/, "")}\n`;
  const oldContents = existsSync(path) ? readFileSync(path, "utf8") : "";
  if (contents === oldContents) {
    console.log(`Configured ${relative(path)} (unchanged).`);
    return;
  }

  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, contents, "utf8");
  console.log(`Configured ${relative(path)}.`);
}

function ensureAppKey() {
  const serverEnv = readEnvFile(serverEnvPath);
  if (valueOrDefault(serverEnv.values.APP_KEY, "") !== "") {
    console.log("Laravel APP_KEY already exists.");
    return;
  }

  runArtisan("key:generate");
}

function runArtisan(command) {
  run("php", ["apps/server/artisan", command]);
}

function run(command, args) {
  const result = spawnSync(command, args, {
    cwd: repoRoot,
    stdio: "inherit",
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

function mergeCsv(current, required) {
  const values = new Set(
    (current ?? "")
      .split(",")
      .map((value) => value.trim())
      .filter(Boolean),
  );

  for (const value of required) {
    values.add(value);
  }

  return [...values].join(",");
}

function normalizeLocalUrl(value) {
  return value.replace(/\/$/, "");
}

function normalizeLocalApiBaseUrl(value) {
  const normalized = normalizeLocalUrl(value);

  try {
    const parsed = new URL(normalized);
    if (parsed.protocol === "http:" && parsed.port === "" && isLoopbackHost(parsed.hostname)) {
      parsed.port = "8000";
    }

    return normalizeLocalUrl(parsed.toString());
  } catch {
    return normalized;
  }
}

function isLoopbackHost(hostname) {
  return hostname === "localhost" || hostname === "127.0.0.1" || hostname === "[::1]";
}

function valueOrDefault(value, fallback) {
  return value === undefined || value.trim() === "" ? fallback : value.trim();
}

function stripQuotes(value) {
  const trimmed = value.trim();
  if (
    (trimmed.startsWith('"') && trimmed.endsWith('"')) ||
    (trimmed.startsWith("'") && trimmed.endsWith("'"))
  ) {
    return trimmed.slice(1, -1);
  }

  return trimmed;
}

function relative(path) {
  return path.replace(`${repoRoot}/`, "");
}

main();
