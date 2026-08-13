#!/usr/bin/env node
// Capture the landing page's feature tour screenshots from the seeded
// Northwood development scenario (M20.2; PUBLIC-008).
//
// The landing page illustrates each major feature area with a screenshot of
// the seeded example organization, committed as a static asset. This script is
// the recapture process: it drives a headless Chrome over the DevTools
// protocol against the local development servers, signs in as the seeded
// persona each surface belongs to, and writes one image per feature tour
// entry into `apps/client/public/assets/marketing/northwood/`.
//
// Because the only data source is `migrate:fresh --seed`'s fictional scenario,
// no real organization's data can appear in the output — but a human still
// reviews every image before committing, because the requirement is about
// what the pictures show, not about where the pipeline points. The process,
// including that review, is documented in
// `docs/process/marketing-screenshot-recapture.md`.
//
// Prerequisites:
//   - a seeded database:   corepack pnpm run server:migrate:seed
//   - the server:          php apps/server/artisan serve  (port 8000)
//   - the admin client:    corepack pnpm run client:dev:admin  (port 5173 —
//                          the only origin the server's CORS policy allows)
//   - Chrome installed (CHROME_BIN overrides the default location)
//
// Usage:
//   node scripts/marketing/capture-northwood-screenshots.mjs [id ...]
//
// With no arguments every entry is captured; naming ids recaptures only those.

import { spawn } from "node:child_process";
import { mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

const SERVER_URL = process.env.MERIDIAN_SERVER_URL ?? "http://127.0.0.1:8000";
const CLIENT_URL = process.env.MERIDIAN_CLIENT_URL ?? "http://localhost:5173";
const LARAVEL_LOG = join(repoRoot, "apps/server/storage/logs/laravel.log");
const OUTPUT_DIR =
  process.env.MERIDIAN_CAPTURE_OUT ??
  join(repoRoot, "apps/client/public/assets/marketing/northwood");

const VIEWPORT = { width: 1280, height: 800, deviceScaleFactor: 2 };

/**
 * One capture per feature tour entry in
 * `apps/client/src/marketing/marketingTour.ts` — the ids must match, and the
 * tour's asset presence test fails if one is missing.
 *
 * `route` may carry `{eventId}` and `{departmentId}`, resolved from the
 * persona's own session document. `expect` is a case-insensitive pattern the
 * rendered page must contain before it is photographed, so a surface that
 * failed to load is an error rather than a committed screenshot of one.
 */
const CAPTURES = [
  {
    id: "applications",
    persona: "olive.organizer@northwood-collective.test",
    route: "/organizer/applications",
    expect: /application/i,
  },
  {
    id: "scheduling",
    // Felix rather than Vera: his board carries open signups and a
    // requirement-gated shift, which is the feature the tour describes.
    persona: "felix.fieldhand@northwood-collective.test",
    route: "/staff/shifts",
    expect: /shift/i,
  },
  {
    id: "operations",
    persona: "sam.shiftlead@northwood-collective.test",
    route: "/events/{eventId}/departments/{departmentId}/logistics",
    expect: /search/i,
  },
  {
    id: "incidents",
    persona: "ingrid.iclead@northwood-collective.test",
    route: "/ims/incidents",
    expect: /incident/i,
    // Past the workspace header, to the incident rows themselves.
    scrollY: 760,
  },
  {
    id: "documents",
    persona: "vera.staff@northwood-collective.test",
    route: "/staff/documents",
    expect: /polic/i,
  },
  {
    id: "qualifications",
    persona: "dana.departmentlead@northwood-collective.test",
    route: "/events/{eventId}/departments/{departmentId}/trainings",
    expect: /training/i,
  },
  {
    id: "equipment",
    persona: "sam.shiftlead@northwood-collective.test",
    route: "/events/{eventId}/departments/{departmentId}/equipment",
    expect: /equipment/i,
    // Past the create form, to the seeded inventory table.
    scrollY: 1150,
  },
  {
    id: "readiness",
    persona: "felix.fieldhand@northwood-collective.test",
    route: "/staff/event-horizon",
    expect: /readiness|outstanding|horizon/i,
  },

  // The other side of each feature, where a real second surface exists: the
  // person the lead's surface is *about*. No persona means no session — the
  // public apply page is photographed the way a visitor meets it.
  {
    id: "applications-apply",
    // The event application form itself — the thing an applicant actually
    // fills in — rather than the event chooser in front of it.
    persona: null,
    route: "/apply/northwood-collective/emberfall-decompression-2026",
    expect: /apply|application/i,
  },
  {
    id: "scheduling-planning",
    persona: "sam.shiftlead@northwood-collective.test",
    route: "/events/{eventId}/departments/{departmentId}/planning",
    expect: /planning|capacity/i,
  },
  {
    id: "incidents-field-report",
    // The form needs a field session — the node must be locked to its event,
    // the state the seed ships in. `avoid` catches the refusal the surface
    // renders when it is not, whose text also contains "Field Report".
    persona: "vera.staff@northwood-collective.test",
    route: "/staff/field-reports/create",
    expect: /field report/i,
    avoid: /session is unavailable/i,
  },
  {
    id: "documents-authoring",
    persona: "olive.organizer@northwood-collective.test",
    route: "/organizer/documents",
    expect: /polic|document/i,
  },
  {
    id: "qualifications-staff",
    persona: "felix.fieldhand@northwood-collective.test",
    route: "/events/{eventId}/departments/{departmentId}/trainings",
    expect: /training/i,
  },
];

function log(message) {
  process.stdout.write(`${message}\n`);
}

function fail(message) {
  process.stderr.write(`\n${message}\n`);
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------------------
// Server API: sign a seeded persona in the way the client does — request a
// login code, read it back out of the development log, and exchange it for a
// bearer token bound to a throwaway device identity.
// ---------------------------------------------------------------------------

async function api(path, options = {}) {
  for (;;) {
    const response = await fetch(`${SERVER_URL}${path}`, {
      ...options,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(options.headers ?? {}),
      },
    });

    // The login endpoints are rate limited on purpose (PUBLIC-005 thinking
    // applied to auth); a capture run signing six personas in trips them.
    // Waiting the limit out is correct — the limit is not.
    if (response.status === 429) {
      const retryAfter = Number(response.headers.get("retry-after") ?? 30);

      log(`  rate limited on ${path}; waiting ${retryAfter}s`);
      await sleep((retryAfter + 1) * 1_000);
      continue;
    }

    const body = await response.json().catch(() => null);

    if (!response.ok) {
      throw new Error(
        `${options.method ?? "GET"} ${path} answered ${response.status}: ${JSON.stringify(body)}`,
      );
    }

    return body;
  }
}

function pemPublicKey(spki) {
  const base64 = Buffer.from(spki).toString("base64");
  const lines = base64.match(/.{1,64}/g) ?? [];

  return `-----BEGIN PUBLIC KEY-----\n${lines.join("\n")}\n-----END PUBLIC KEY-----`;
}

let devicePromise = null;

/** One throwaway device identity for the whole run, shaped as the client's. */
function captureDevice() {
  devicePromise ??= (async () => {
    const pair = await crypto.subtle.generateKey(
      {
        name: "RSASSA-PKCS1-v1_5",
        modulusLength: 2048,
        publicExponent: new Uint8Array([1, 0, 1]),
        hash: "SHA-256",
      },
      true,
      ["sign", "verify"],
    );
    const spki = await crypto.subtle.exportKey("spki", pair.publicKey);

    return {
      id: crypto.randomUUID(),
      label: "Meridian screenshot capture",
      platform: "web",
      public_key: pemPublicKey(spki),
    };
  })();

  return devicePromise;
}

function logSize() {
  try {
    return statSync(LARAVEL_LOG).size;
  } catch {
    return 0;
  }
}

async function readLoginCode(sinceOffset) {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const appended = readFileSync(LARAVEL_LOG, "utf8").slice(sinceOffset);
    const block = appended.split("Enter this code").at(-1) ?? "";
    const code = block.match(/\b([A-Z0-9]{4}-[A-Z0-9]{4})\b/)?.[1];

    if (appended.includes("Enter this code") && code !== undefined) {
      return code;
    }

    await sleep(500);
  }

  throw new Error(
    `no login code appeared in ${LARAVEL_LOG} — is the mail driver the development log?`,
  );
}

const tokens = new Map();

async function signIn(email) {
  if (tokens.has(email)) {
    return tokens.get(email);
  }

  const offset = logSize();

  await api("/api/auth/magic-link", {
    method: "POST",
    body: JSON.stringify({ email }),
  });

  const code = await readLoginCode(offset);
  const device = await captureDevice();
  const issued = await api("/api/auth/magic-link/verify", {
    method: "POST",
    body: JSON.stringify({
      email,
      code,
      client_name: device.label,
      device,
    }),
  });

  const session = await api("/api/me", {
    headers: { Authorization: `Bearer ${issued.token}` },
  });

  const signedIn = {
    stored: {
      version: 1,
      token: issued.token,
      user: {
        id: String(issued.user.id),
        name: issued.user.name ?? "",
        email: issued.user.email ?? "",
      },
      deviceId: issued.device?.id ?? null,
      expiresAt: issued.expires_at ?? null,
    },
    eventId: session.context?.event_id ?? session.events?.[0]?.id ?? null,
    departmentId: session.departments?.[0]?.id ?? null,
  };

  tokens.set(email, signedIn);
  log(`  signed in ${email}`);

  return signedIn;
}

// ---------------------------------------------------------------------------
// Chrome, over the DevTools protocol. No dependency carries this: the repo has
// no browser-automation package, and one page, one navigation at a time needs
// nothing more than a WebSocket and four domains.
// ---------------------------------------------------------------------------

class Cdp {
  constructor(socket) {
    this.socket = socket;
    this.nextId = 1;
    this.pending = new Map();
    this.listeners = new Set();

    socket.addEventListener("message", (event) => {
      const message = JSON.parse(event.data);

      if (message.id !== undefined && this.pending.has(message.id)) {
        const { resolve: ok, reject } = this.pending.get(message.id);
        this.pending.delete(message.id);

        if (message.error) {
          reject(new Error(`${message.error.message}`));
        } else {
          ok(message.result);
        }

        return;
      }

      for (const listener of this.listeners) {
        listener(message);
      }
    });
  }

  send(method, params = {}, sessionId = undefined) {
    const id = this.nextId++;

    return new Promise((ok, reject) => {
      this.pending.set(id, { resolve: ok, reject });
      this.socket.send(JSON.stringify({ id, method, params, sessionId }));
    });
  }

  waitForEvent(method, sessionId, timeoutMs = 30_000) {
    return new Promise((ok, reject) => {
      const timer = setTimeout(() => {
        this.listeners.delete(listener);
        reject(new Error(`timed out waiting for ${method}`));
      }, timeoutMs);

      const listener = (message) => {
        if (message.method === method && message.sessionId === sessionId) {
          clearTimeout(timer);
          this.listeners.delete(listener);
          ok(message.params);
        }
      };

      this.listeners.add(listener);
    });
  }
}

function chromeBinary() {
  return (
    process.env.CHROME_BIN ??
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe"
  );
}

async function launchChrome() {
  const profile = mkdtempSync(join(tmpdir(), "meridian-capture-"));
  const child = spawn(
    chromeBinary(),
    [
      "--headless=new",
      "--remote-debugging-port=0",
      `--user-data-dir=${profile}`,
      "--no-first-run",
      "--no-default-browser-check",
      "--disable-gpu",
      "--hide-scrollbars",
      "--force-device-scale-factor=1",
      "--lang=en-US",
      "about:blank",
    ],
    { stdio: "ignore" },
  );

  const portFile = join(profile, "DevToolsActivePort");
  let port = null;

  for (let attempt = 0; attempt < 60 && port === null; attempt += 1) {
    try {
      port = Number(readFileSync(portFile, "utf8").split("\n")[0]);
    } catch {
      await sleep(250);
    }
  }

  if (port === null) {
    child.kill();
    throw new Error("Chrome did not report a DevTools port");
  }

  const version = await (
    await fetch(`http://127.0.0.1:${port}/json/version`)
  ).json();
  const socket = new WebSocket(version.webSocketDebuggerUrl);

  await new Promise((ok, reject) => {
    socket.addEventListener("open", ok, { once: true });
    socket.addEventListener("error", reject, { once: true });
  });

  return {
    cdp: new Cdp(socket),
    close: () => {
      try {
        socket.close();
      } catch {
        /* closing anyway */
      }

      child.kill();
      // Give Chrome a beat to let go of the profile before deleting it.
      setTimeout(() => {
        try {
          rmSync(profile, { recursive: true, force: true });
        } catch {
          /* a leftover temp profile is not worth failing the run over */
        }
      }, 1_000).unref();
    },
  };
}

async function evaluate(cdp, sessionId, expression) {
  const result = await cdp.send(
    "Runtime.evaluate",
    { expression, returnByValue: true, awaitPromise: true },
    sessionId,
  );

  if (result.exceptionDetails) {
    throw new Error(
      `page evaluation failed: ${result.exceptionDetails.text} ${result.exceptionDetails.exception?.description ?? ""}`,
    );
  }

  return result.result.value;
}

async function navigate(cdp, sessionId, url) {
  const loaded = cdp.waitForEvent("Page.loadEventFired", sessionId);

  await cdp.send("Page.navigate", { url }, sessionId);
  await loaded;
}

/**
 * Wait until the rendered text settles: two identical readings half a second
 * apart, with no loading indicator on screen. Surfaces load their own data
 * after the document does, and a screenshot of a spinner is not a screenshot
 * of a feature.
 */
async function settle(cdp, sessionId, timeoutMs = 30_000) {
  const started = Date.now();
  let previous = null;

  while (Date.now() - started < timeoutMs) {
    await sleep(500);

    const text = await evaluate(cdp, sessionId, "document.body.innerText");

    if (
      previous !== null &&
      text === previous &&
      !/\bloading\b/i.test(text) &&
      text.trim() !== ""
    ) {
      return text;
    }

    previous = text;
  }

  throw new Error("the page never settled");
}

async function main() {
  const only = new Set(process.argv.slice(2));
  const captures = CAPTURES.filter(
    (capture) => only.size === 0 || only.has(capture.id),
  );

  if (captures.length === 0) {
    fail(`no captures match ${[...only].join(", ")}`);
  }

  const health = await fetch(`${SERVER_URL}/api/health`).catch(() => null);

  if (health === null || !health.ok) {
    fail(`no server answered at ${SERVER_URL} — start it and seed first`);
  }

  const client = await fetch(CLIENT_URL).catch(() => null);

  if (client === null || !client.ok) {
    fail(
      `no client answered at ${CLIENT_URL} — run the admin client on port 5173`,
    );
  }

  mkdirSync(OUTPUT_DIR, { recursive: true });

  const { cdp, close } = await launchChrome();

  try {
    const { targetId } = await cdp.send("Target.createTarget", {
      url: "about:blank",
    });
    const { sessionId } = await cdp.send("Target.attachToTarget", {
      targetId,
      flatten: true,
    });

    await cdp.send("Page.enable", {}, sessionId);
    await cdp.send("Runtime.enable", {}, sessionId);
    await cdp.send(
      "Emulation.setDeviceMetricsOverride",
      { ...VIEWPORT, mobile: false },
      sessionId,
    );

    // Land on the client origin once so localStorage belongs to it.
    await navigate(cdp, sessionId, `${CLIENT_URL}/login`);

    for (const capture of captures) {
      log(`capturing ${capture.id} (${capture.persona ?? "no session"})`);

      // A capture with no persona is a public surface — the apply page, say —
      // photographed the way a visitor meets it: holding nothing.
      const signedIn =
        capture.persona === null ? null : await signIn(capture.persona);
      const route = capture.route
        .replace("{eventId}", String(signedIn?.eventId))
        .replace("{departmentId}", String(signedIn?.departmentId));

      if (route.includes("null") || route.includes("undefined")) {
        throw new Error(
          `${capture.id}: ${capture.persona}'s session resolved no event or department for ${capture.route}`,
        );
      }

      // Hand the page this persona's credential and nothing of the last
      // one's, then load the surface fresh so every module boots from it.
      await evaluate(
        cdp,
        sessionId,
        `localStorage.clear(); ${
          signedIn === null
            ? ""
            : `localStorage.setItem(${JSON.stringify(
                "meridian.api-token.v1",
              )}, ${JSON.stringify(JSON.stringify(signedIn.stored))});`
        }`,
      );
      await navigate(cdp, sessionId, `${CLIENT_URL}${route}`);

      // Screenshots are captured on the dark scheme unless an entry says
      // otherwise; the OS preference of whoever runs this must not decide.
      await cdp.send(
        "Emulation.setEmulatedMedia",
        {
          features: [
            {
              name: "prefers-color-scheme",
              value: capture.colorScheme ?? "dark",
            },
          ],
        },
        sessionId,
      );

      const text = await settle(cdp, sessionId);

      if (capture.avoid !== undefined && capture.avoid.test(text)) {
        throw new Error(
          `${capture.id}: ${route} rendered the ${capture.avoid} state this capture must not photograph — got:\n${text.slice(0, 400)}`,
        );
      }

      if (capture.scrollY !== undefined) {
        // Twice, with a beat between: a late render can put the page back at
        // the top, and a screenshot of the wrong scroll position is silent.
        for (let attempt = 0; attempt < 2; attempt += 1) {
          await evaluate(
            cdp,
            sessionId,
            `window.scrollTo(0, ${capture.scrollY});`,
          );
          await sleep(600);

          const scrolled = await evaluate(cdp, sessionId, "window.scrollY");

          if (scrolled > 0) {
            break;
          }
        }
      }

      if (!capture.expect.test(text)) {
        throw new Error(
          `${capture.id}: ${route} rendered without ${capture.expect} — got:\n${text.slice(0, 400)}`,
        );
      }

      const shot = await cdp.send(
        "Page.captureScreenshot",
        { format: "webp", quality: 92 },
        sessionId,
      );
      const file = join(OUTPUT_DIR, `${capture.id}.webp`);

      writeFileSync(file, Buffer.from(shot.data, "base64"));
      log(`  wrote ${file}`);
    }
  } finally {
    close();
  }

  log(
    "\nDone. Review every image before committing: the only acceptable " +
      "content is the fictional Northwood scenario (PUBLIC-008).",
  );
}

main().catch((error) => fail(error.stack ?? String(error)));
