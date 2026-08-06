#!/usr/bin/env node

/**
 * Start a Meridian Kiosk desktop instance as a trusted shared workstation
 * (M18.32; technical spec 13.1, 13.2; UI contract 12.8).
 *
 * One command, nothing manual. A Kiosk cannot be exercised without three things
 * that used to be three separate errands — a trusted workstation row, a pinned
 * organization and event, and a login code in somebody's hand — and a developer
 * who wants to test switching users needs two of the third. This does all of it:
 *
 *  1. asks the node to provision (or reuse) the workstation and issue the codes;
 *  2. writes what it learned into `apps/desktop/.env.shared-workstation`, which
 *     is the machine's own configuration and survives the next run;
 *  3. starts the Kiosk Vite dev server on a port of its own, so it sits beside a
 *     shared client already holding 5173;
 *  4. starts the Electron wrapper against it, which hands the workstation
 *     identity to the Kiosk through its preload.
 *
 * Nothing here is a credential store. The workstation identifier grants nothing
 * (AUTH-030) — the node still requires a login code issued to a named person and
 * still requires the workstation to be trusted. The codes are printed once, to
 * the terminal of the person who asked for them, which is what the God Mode
 * screen does on the same node.
 *
 * Arguments are passed through to `php artisan meridian:shared-workstation`, so
 * `--event=`, `--department=`, `--name=`, `--code-for=`, and `--codes=` all work
 * here.
 */

import { spawn, spawnSync } from "node:child_process";
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, relative as relativePath, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

const serverEnvPath = resolve(repoRoot, "apps/server/.env");
const workstationEnvPath = resolve(repoRoot, "apps/desktop/.env.shared-workstation");
const workstationEnvExamplePath = resolve(
  repoRoot,
  "apps/desktop/.env.shared-workstation.example",
);

/**
 * The Kiosk's own dev-server port.
 *
 * Fixed rather than automatic, and strict rather than falling forward, because
 * the browser refuses a cross-origin call from an origin the node does not
 * allow: a Vite that quietly moved to the next free port would produce a Kiosk
 * that renders and cannot reach anything, which is a confusing way to fail.
 */
// Kept in step with `dev:kiosk:workstation` in the client's package.json, which
// is where the port is actually applied — passing it through `pnpm run -- ...`
// hands vite a `--` it treats as end-of-options, and the server quietly lands on
// another port with an origin the node does not allow.
const KIOSK_PORT = 5175;
const KIOSK_HOST = "127.0.0.1";
const KIOSK_ORIGINS = [`http://127.0.0.1:${KIOSK_PORT}`, `http://localhost:${KIOSK_PORT}`];
const KIOSK_URL = `http://${KIOSK_HOST}:${KIOSK_PORT}/`;

const children = [];
let shuttingDown = false;

async function main() {
  ensureWorkstationEnvExists();
  ensureCorsAllowsKiosk();
  ensureSchemaIsCurrent();

  const provisioned = provisionWorkstation(process.argv.slice(2));

  writeEnvFile(workstationEnvPath, {
    MERIDIAN_SHARED_WORKSTATION_ID: provisioned.shared_workstation_id,
  });

  const env = {
    ...process.env,
    ...readEnvFile(workstationEnvPath).values,
    MERIDIAN_SHARED_WORKSTATION_ID: provisioned.shared_workstation_id,
    MERIDIAN_CLIENT_DEV_SERVER_URL: KIOSK_URL,
  };

  reportProvisioned(provisioned);

  startKioskDevServer();
  await waitForKiosk();
  startDesktopWrapper(env);
}

function ensureWorkstationEnvExists() {
  if (existsSync(workstationEnvPath)) {
    return;
  }

  copyFileSync(workstationEnvExamplePath, workstationEnvPath);
  console.log(`Created ${relative(workstationEnvPath)} from its example.`);
}

/**
 * Make sure the node will answer this Kiosk's origin.
 *
 * Additive, and only when something is missing. A developer's `.env` is theirs;
 * what this does is add the origin the thing it is about to start needs, and say
 * that it did. Without it the Kiosk comes up looking healthy and every request
 * it makes is refused by the browser before the node ever sees it.
 */
function ensureCorsAllowsKiosk() {
  if (!existsSync(serverEnvPath)) {
    console.log(
      `No ${relative(serverEnvPath)} yet; the node's default CORS origins already allow ${KIOSK_ORIGINS[0]}.`,
    );

    return;
  }

  const current = readEnvFile(serverEnvPath).values.MERIDIAN_CORS_ALLOWED_ORIGINS;

  if (current === undefined) {
    return;
  }

  const merged = mergeCsv(current, KIOSK_ORIGINS);

  if (merged === current) {
    return;
  }

  writeEnvFile(serverEnvPath, { MERIDIAN_CORS_ALLOWED_ORIGINS: merged });
  console.log(
    `Added ${KIOSK_ORIGINS.join(" and ")} to MERIDIAN_CORS_ALLOWED_ORIGINS in ${relative(serverEnvPath)}. Restart the node for it to take effect.`,
  );
}

/**
 * Bring the node's schema up to date before asking it for anything.
 *
 * A workstation with no pinned context is a row `shared_workstations` could not
 * hold until M18.32 made two columns nullable, so a database that predates the
 * branch fails with a not-null violation from inside the provisioning command —
 * which is a confusing way to learn you need to migrate. Additive migrations
 * only, which is what anybody does after changing branches anyway.
 */
function ensureSchemaIsCurrent() {
  const result = spawnSync("php", ["apps/server/artisan", "migrate", "--force"], {
    cwd: repoRoot,
    encoding: "utf8",
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    console.error(`${result.stdout ?? ""}${result.stderr ?? ""}`.trim());
    console.error("\nThe node's migrations did not run. Is the database up? pnpm run db:up");
    process.exit(result.status ?? 1);
  }

  const ran = (result.stdout ?? "").includes("DONE");

  if (ran) {
    console.log("Applied pending migrations to the node.");
  }
}

/**
 * Ask the node for a trusted, pinned workstation and its login codes.
 *
 * The node does the work, through the same services God Mode uses, so the pin is
 * audited and the codes are stored as hashes exactly as they would be if a
 * console operator had issued them.
 */
function provisionWorkstation(passthroughArgs) {
  const result = spawnSync(
    "php",
    ["apps/server/artisan", "meridian:shared-workstation", "--json", ...passthroughArgs],
    { cwd: repoRoot, encoding: "utf8" },
  );

  if (result.error) {
    throw result.error;
  }

  const output = `${result.stdout ?? ""}${result.stderr ?? ""}`;

  if (result.status !== 0) {
    console.error(output.trim());
    console.error("\nCould not provision a shared workstation. Is the node migrated and seeded?");
    process.exit(result.status ?? 1);
  }

  // The command may have warned about a user it could not issue a code for
  // before printing the payload, so the JSON is taken from the braces rather
  // than from the whole of stdout.
  const start = output.indexOf("{");
  const end = output.lastIndexOf("}");

  if (start === -1 || end === -1) {
    console.error(output.trim());
    console.error("\nThe node did not answer with a workstation.");
    process.exit(1);
  }

  return JSON.parse(output.slice(start, end + 1));
}

function reportProvisioned(provisioned) {
  console.log("");
  console.log(`Shared workstation  ${provisioned.shared_workstation_name}`);
  console.log(`Organization        ${provisioned.organization}`);
  console.log(`Event               ${provisioned.event}`);
  console.log(`Department          ${provisioned.department ?? "The whole site"}`);
  console.log("");

  if (!provisioned.login_codes || provisioned.login_codes.length === 0) {
    console.log("No login codes were issued, so nobody can sign in at this workstation yet.");
    console.log("Re-run with --code-for=someone@example.com to issue one.");
    console.log("");

    return;
  }

  console.log("Login codes — shown once, the same way the God Mode screen shows them:");
  for (const code of provisioned.login_codes) {
    console.log(`  ${code.code}   ${code.name} <${code.email}>`);
  }
  console.log("");
  console.log(
    "Sign in with the first, then use Switch user on the dashboard and sign in with the second.",
  );
  console.log("");
  console.log(
    "The window opens fullscreen, because that is what a Kiosk is. Alt+F4 closes it, Ctrl+Shift+H is the health panel,",
  );
  console.log(
    `and MERIDIAN_DESKTOP_WINDOWED=true in ${relative(workstationEnvPath)} opens it in a window instead.`,
  );
  console.log("");
}

function startKioskDevServer() {
  children.push(
    spawn(
      "corepack",
      ["pnpm", "--filter", "@meridian/client", "run", "dev:kiosk:workstation"],
      { cwd: repoRoot, stdio: "inherit", shell: process.platform === "win32" },
    ),
  );
}

/** Wait for the Kiosk dev server to answer before opening a window at it. */
async function waitForKiosk() {
  const deadline = Date.now() + 60_000;

  for (;;) {
    try {
      await fetch(KIOSK_URL, { method: "HEAD" });

      return;
    } catch {
      if (Date.now() > deadline) {
        console.error(`The Kiosk dev server did not come up at ${KIOSK_URL}.`);
        shutdown(1);

        return;
      }

      await new Promise((wake) => setTimeout(wake, 250));
    }
  }
}

function startDesktopWrapper(env) {
  const wrapper = spawn(
    "corepack",
    ["pnpm", "--filter", "@meridian/desktop", "run", "start"],
    { cwd: repoRoot, stdio: "inherit", env, shell: process.platform === "win32" },
  );

  children.push(wrapper);

  // Closing the Kiosk window ends the session, which is the whole point of the
  // command; leaving a dev server behind afterwards is just a port somebody has
  // to go and find later.
  wrapper.on("exit", (code) => shutdown(code ?? 0));
}

function shutdown(code) {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;

  for (const child of children) {
    if (!child.killed) {
      child.kill();
    }
  }

  process.exit(code);
}

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => shutdown(0));
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

function writeEnvFile(path, updates) {
  const existing = readEnvFile(path);
  const remaining = new Map(Object.entries(updates));
  const nextLines = existing.lines.map((line) => {
    const match = /^([A-Za-z_][A-Za-z0-9_]*)=/.exec(line);

    if (match === null || !remaining.has(match[1])) {
      return line;
    }

    const value = remaining.get(match[1]);
    remaining.delete(match[1]);

    return `${match[1]}=${value}`;
  });

  for (const [key, value] of remaining) {
    nextLines.push(`${key}=${value}`);
  }

  const contents = `${nextLines.join("\n").replace(/\n+$/, "")}\n`;

  if (existsSync(path) && contents === readFileSync(path, "utf8")) {
    return;
  }

  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, contents, "utf8");
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

function stripQuotes(value) {
  const trimmed = value.trim();

  return (trimmed.startsWith('"') && trimmed.endsWith('"')) ||
    (trimmed.startsWith("'") && trimmed.endsWith("'"))
    ? trimmed.slice(1, -1)
    : trimmed;
}

function relative(path) {
  return relativePath(repoRoot, path).replace(/\\/g, "/");
}

await main();
