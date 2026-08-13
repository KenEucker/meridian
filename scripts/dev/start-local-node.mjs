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
import { createSocket } from "node:dgram";
import { existsSync, mkdtempSync, writeFileSync } from "node:fs";
import { networkInterfaces, tmpdir } from "node:os";
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
const dockerContainersStarted = [];

function stop(code) {
  if (stopping) {
    return;
  }
  stopping = true;

  /*
   * Killing the docker CLI does not reliably kill the container it attached,
   * so containers are removed by name — which also clears the way for the
   * next run.
   */
  for (const name of dockerContainersStarted) {
    spawnSync("docker", ["rm", "-f", name], { stdio: "ignore" });
  }

  for (const child of children) {
    child.kill();
  }

  process.exit(code);
}

process.on("SIGINT", () => stop(0));
process.on("SIGTERM", () => stop(0));

/*
 * The address the network should answer `meridian.home.arpa` with — detected
 * at start, every start, because it is a fact about the network this machine
 * is standing on right now. A connected UDP socket reports which local
 * address routes toward the internet, which is the interface phones share;
 * reading the interface list instead would offer the WSL and Hyper-V adapters
 * as equals, and a DNS record pointing at one of those answers nobody.
 */
function detectLanAddress() {
  return new Promise((resolveAddress) => {
    const probe = createSocket("udp4");

    probe.once("error", () => {
      probe.close();
      resolveAddress(null);
    });

    probe.connect(53, "8.8.8.8", () => {
      const { address } = probe.address();
      probe.close();
      resolveAddress(address);
    });
  });
}

const lanAddress =
  (await detectLanAddress()) ??
  (Object.values(networkInterfaces())
    .flat()
    .find((entry) => entry && entry.family === "IPv4" && !entry.internal)
    ?.address ??
    null);

if (lanAddress === null) {
  console.error(
    "No usable network address found; a phone cannot reach this machine. " +
      "Connect to the network the devices are on and rerun.",
  );
  process.exit(1);
}

const withDns = process.argv.includes("--with-dns");

console.log("Starting a local Meridian node on the on-site convention names.");
console.log("");
console.log(`  This machine's address on the current network: ${lanAddress}`);
if (!withDns) {
  console.log("  Point the network's DNS record for meridian.home.arpa at it");
  console.log("  (router local-DNS entry, or deploy/dns/onsite-dnsmasq.conf) —");
  console.log("  or rerun with --with-dns and this machine answers the name itself.");
}
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
const DOCKER_DNS_NAME = "meridian-local-node-dns";

/** Whether the Docker daemon is up, not merely whether a CLI is installed. */
function dockerAvailable() {
  return spawnSync("docker", ["info"], { stdio: "ignore" }).status === 0;
}

const dockerIsUp = dockerAvailable();

if (dockerIsUp) {
  // A previous run that died without cleanup would otherwise block the name.
  spawnSync("docker", ["rm", "-f", DOCKER_PROXY_NAME], { stdio: "ignore" });

  dockerContainersStarted.push(DOCKER_PROXY_NAME);
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

/*
 * `--with-dns`: this machine answers `meridian.home.arpa` itself, for
 * networks whose router cannot serve local DNS records (Starlink's and
 * Google's can't). CoreDNS in Docker answers the convention names with this
 * machine's address and forwards everything else, so a phone pointed at this
 * machine for DNS loses nothing.
 *
 * Bound to the detected LAN address, not the wildcard: Windows Internet
 * Connection Sharing — which WSL2 and therefore Docker Desktop depend on —
 * holds 0.0.0.0:53/udp, and a wildcard listener beside it receives nothing.
 * UDP only, because Docker's port proxy refuses the TCP half on some
 * machines and phones resolve over UDP.
 *
 * Opt-in rather than default: taking over a network's name resolution is a
 * decision, not a side effect.
 */
if (withDns) {
  if (!dockerIsUp) {
    console.error("--with-dns needs Docker running; start Docker Desktop and rerun.");
    stop(1);
  }

  const corefile = join(mkdtempSync(join(tmpdir(), "meridian-dns-")), "Corefile");
  writeFileSync(
    corefile,
    /*
     * `log` on both blocks on purpose: this configuration exists to be
     * diagnosed. "Did the phone's query ever arrive" is the whole question
     * when a device cannot resolve the node, and the container's log answers
     * it per query.
     */
    [
      /*
       * No `fallthrough`: this server is authoritative for home.arpa (RFC
       * 8375 — the name is answered locally or not at all), so a query it
       * cannot answer from the hosts list is NODATA, not somebody else's
       * problem. Falling through with nothing behind it returned SERVFAIL
       * for the HTTPS record type Chrome asks for before every navigation,
       * and a hard failure there pushes the browser toward an HTTPS upgrade
       * against a node that serves plain HTTP.
       */
      "home.arpa {",
      "    log",
      "    hosts {",
      `        ${lanAddress} meridian.home.arpa`,
      `        ${lanAddress} meridian2.home.arpa`,
      `        ${lanAddress} meridian3.home.arpa`,
      "    }",
      "}",
      ". {",
      "    log",
      "    forward . 1.1.1.1 8.8.8.8",
      "    cache 30",
      "}",
      "",
    ].join("\n"),
  );

  spawnSync("docker", ["rm", "-f", DOCKER_DNS_NAME], { stdio: "ignore" });

  dockerContainersStarted.push(DOCKER_DNS_NAME);
  run("CoreDNS (Docker)", "docker", [
    "run",
    "--rm",
    "--name",
    DOCKER_DNS_NAME,
    "-p",
    `${lanAddress}:53:53/udp`,
    "-v",
    `${corefile.replaceAll("\\", "/")}:/Corefile:ro`,
    "coredns/coredns:latest",
    "-conf",
    "/Corefile",
  ]);

  console.log("");
  console.log(`  DNS is up: point the phone's Wi-Fi DNS at ${lanAddress} — or the`);
  console.log("  router's, where it can be set — and it resolves the node here.");
  console.log("");
}

/*
 * The Windows firewall drops inbound LAN traffic to a published container
 * port unless a rule allows it, and it drops rather than refuses — so the
 * symptom on the phone is a connection that times out with nothing in any
 * log on this machine. Worth stating up front rather than leaving somebody
 * to discover it: local traffic and Docker's own bridge both bypass the
 * filter, so every test run *on* this machine passes while every phone on
 * the network hangs.
 */
if (process.platform === "win32") {
  console.log("  Windows firewall: phones need these inbound rules. Run once, in an");
  console.log("  administrator terminal (harmless to re-run):");
  console.log(
    '      netsh advfirewall firewall add rule name="Meridian local node" dir=in action=allow protocol=TCP localport=80',
  );
  if (withDns) {
    console.log(
      '      netsh advfirewall firewall add rule name="Meridian local DNS" dir=in action=allow protocol=UDP localport=53',
    );
  }
  console.log("");
}
