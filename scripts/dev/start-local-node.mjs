#!/usr/bin/env node
/**
 * Run this machine as a local Meridian node on the on-site convention names
 * (technical spec 8.4; RFC 8375).
 *
 * Two processes: the Laravel dev server listening on every interface — a phone
 * cannot reach a loopback-bound server — and Caddy serving
 * `meridian.home.arpa` and its numbered siblings over plain HTTP in front of
 * it (`deploy/caddy/Caddyfile.home-arpa`, which states the cleartext trade).
 * An installed Meridian Field app on the same network then finds this machine
 * at boot with nothing typed on the phone.
 *
 * Caddy runs in Docker by default — `caddy:2-alpine`, the image the deployment
 * stack already uses — so a fresh clone needs no Caddy install. A machine
 * without Docker falls back to a `caddy` binary on the PATH. Inside the
 * container, loopback is the container, which is why the upstream becomes
 * `host.docker.internal` there; the `host-gateway` mapping makes that name
 * work on Linux engines too, where Docker does not define it by itself.
 *
 * What this script cannot do is answer DNS: something on the network has to
 * resolve `meridian.home.arpa` to this machine. The router's local-DNS entry
 * or the dnsmasq fragment in `deploy/dns` covers phones; a hosts-file line
 * covers this laptop's own browser. The addresses printed on start are the
 * ones to point the record at.
 *
 * Usage:
 *   corepack pnpm run node:local
 */

import { spawn, spawnSync } from "node:child_process";
import { existsSync } from "node:fs";
import { networkInterfaces } from "node:os";
import { join, resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");

/*
 * Fail before spawning anything, with the command that fixes it. A missing
 * vendor directory otherwise surfaces as an artisan stack trace, and a missing
 * Caddy as a Windows "not recognized" — neither names the actual next step.
 */
if (!existsSync(join(repoRoot, "apps", "server", "vendor"))) {
  console.error(
    "apps/server has no vendor directory. Run `corepack pnpm run server:install` " +
      "first (or `corepack pnpm run setup:local` from a fresh clone).",
  );
  process.exit(1);
}

if (!existsSync(join(repoRoot, "apps", "server", ".env"))) {
  console.error(
    "apps/server has no .env. Run `corepack pnpm run setup:local` first.",
  );
  process.exit(1);
}

const children = [];

function run(label, command, args) {
  const child = spawn(command, args, {
    cwd: repoRoot,
    stdio: "inherit",
  });

  child.on("error", (error) => {
    if (error.code === "ENOENT") {
      console.error(
        `\`${command}\` is not installed or not on PATH.` +
          (command === "caddy"
            ? " Install it from https://caddyserver.com/docs/install and rerun."
            : ""),
      );
    } else {
      console.error(`${label} failed to start: ${error.message}`);
    }
    stop(1);
  });

  /*
   * Either process ending ends the node: a proxy with no upstream answers 502
   * to every phone on the network, which is worse than being visibly down.
   */
  child.on("exit", (code) => stop(code ?? 0));
  children.push(child);

  return child;
}

let stopping = false;
let dockerProxyStarted = false;

function stop(code) {
  if (stopping) {
    return;
  }
  stopping = true;

  /*
   * Killing the docker CLI does not reliably kill the container it attached,
   * so the container is removed by name — which also clears the way for the
   * next run.
   */
  if (dockerProxyStarted) {
    spawnSync("docker", ["rm", "-f", DOCKER_PROXY_NAME], { stdio: "ignore" });
  }

  for (const child of children) {
    child.kill();
  }

  process.exit(code);
}

process.on("SIGINT", () => stop(0));
process.on("SIGTERM", () => stop(0));

const lanAddresses = Object.values(networkInterfaces())
  .flat()
  .filter((entry) => entry && entry.family === "IPv4" && !entry.internal)
  .map((entry) => entry.address);

console.log("Starting a local Meridian node on the on-site convention names.");
console.log("");
console.log("  This machine's LAN addresses: " + (lanAddresses.join(", ") || "(none found)"));
console.log("  Point the network's DNS record for meridian.home.arpa at one of them");
console.log("  (router local-DNS entry, or deploy/dns/onsite-dnsmasq.conf).");
console.log("  For this machine's own browser, a hosts-file line does it:");
console.log("      127.0.0.1 meridian.home.arpa");
console.log("");

run("The Meridian server", "php", [
  join("apps", "server", "artisan"),
  "serve",
  // Every interface, not loopback: the phones this node exists for are not on
  // this machine.
  "--host",
  "0.0.0.0",
  "--port",
  "8000",
]);

const DOCKER_PROXY_NAME = "meridian-local-node-proxy";

/** Whether the Docker daemon is up, not merely whether a CLI is installed. */
function dockerAvailable() {
  return spawnSync("docker", ["info"], { stdio: "ignore" }).status === 0;
}

if (dockerAvailable()) {
  // A previous run that died without cleanup would otherwise block the name.
  spawnSync("docker", ["rm", "-f", DOCKER_PROXY_NAME], { stdio: "ignore" });

  dockerProxyStarted = true;
  run("Caddy (Docker)", "docker", [
    "run",
    "--rm",
    "--name",
    DOCKER_PROXY_NAME,
    "-p",
    "80:80",
    // Loopback inside the container is the container; this name is the host.
    // Docker Desktop defines it on its own, Linux engines need the mapping.
    "--add-host",
    "host.docker.internal:host-gateway",
    "-e",
    "MERIDIAN_LOCAL_UPSTREAM=host.docker.internal:8000",
    "-v",
    `${join(repoRoot, "deploy", "caddy", "Caddyfile.home-arpa").replaceAll("\\", "/")}:/etc/caddy/Caddyfile:ro`,
    "caddy:2-alpine",
  ]);
} else {
  console.log(
    "Docker is not running, so Caddy runs from the PATH instead. Start Docker",
  );
  console.log(
    "Desktop (or install caddy: https://caddyserver.com/docs/install) to change that.",
  );
  console.log("");

  run("Caddy", "caddy", [
    "run",
    "--config",
    join("deploy", "caddy", "Caddyfile.home-arpa"),
  ]);
}
