# Meridian as a Runtipi app

Traceability: technical spec 26.1 (environments), 26.2 (production/event
safeguards), 26.4 (application packaging), 26.7 (release artifacts), 8.2 and 8.6
(secure connection policy, HTTPS validation).

This directory is the agreed design for how a Meridian node is installed on
[Runtipi](https://runtipi.io) — first as a custom install a person can do
today, then as a published app store other people can install from. The
mechanism is implemented: M19.26 published the images, M19.27 added the
reverse-proxy deployment mode, and M19.28 added the app generator
(`scripts/deploy/build-runtipi-app.mjs`) and the store-side runbook
(`docs/process/runtipi-distribution.md`). What remains open is M19.29's real
install: [`QA-RUNTIPI-01`](../../docs/qa/QA-RUNTIPI-01-runtipi-app-install.md)
exercises the path on a real Runtipi host and closes the two open questions at
the bottom of this document with observed behavior.
Nothing here changes how the `deploy/docker` or `deploy/native` bundles work; the
Runtipi app is a fourth way to run the same server image, not a fourth way to
build one.

## What Runtipi is, in the terms this repository already uses

Runtipi is a self-hosted app platform: a Docker host with a web dashboard, a
Traefik reverse proxy in front of every app, and an "app store" that is just a
git repository of Compose files plus metadata. Installing an app means Runtipi
clones a store repo, renders the app's `docker-compose.yml` with values a user
typed into a form, and brings the stack up behind Traefik.

For Meridian this maps almost one-to-one onto `deploy/docker/compose.deployment.yaml`.
Three things do not map, and they are what the work below is:

1. **Runtipi pulls images. Meridian did not publish any.** The release workflow
   attaches `docker save` tarballs to the GitHub release
   (`.github/workflows/release-artifacts.yml`). Runtipi runs `docker pull`. This
   is a hard prerequisite for every path below — closed by M19.26, which makes
   that workflow also push both images to GHCR on a version tag.
2. **Runtipi owns TLS and port 80/443.** The stack's `web` service publishes
   `80:80` and `443:443` and terminates TLS itself. Under Runtipi it must publish
   nothing and serve plain HTTP on `:80` behind Traefik.
3. **`APP_KEY` cannot be generated inside a container.** `SecretGenerator`
   deliberately refuses to write a key it cannot persist
   (`apps/server/app/Services/Secrets/SecretGenerator.php`), and `APP_KEY` is
   `required: true`, so a node without one refuses to serve. A Runtipi install is
   one click and has no "run `key:generate`, then edit a file" step.

## Prerequisite: publish the images

Everything else waits on this. **Implemented (M19.26):**
`.github/workflows/release-artifacts.yml` pushes the two existing Dockerfile
targets to GHCR on a version tag, alongside the tarballs it already produces;
`scripts/release/validate-release-workflow.mjs` holds the pushed tag to the
root `package.json` version, the tarball artifacts unchanged, and the amd64-only
decision on every pull request. The first pullable version is the first release
tagged after M19.26 landed:

- `ghcr.io/keneucker/meridian-server:<version>`
- `ghcr.io/keneucker/meridian-server-web:<version>`

Constraints that fall out of the current build:

- The tag stays the root `package.json` version. `scripts/deploy/build-images.mjs`
  is already the only thing that decides it, and `MERIDIAN_IMAGE` already exists
  in `.env.deployment.example` for "set a registry prefix here when the images
  are pulled rather than built on the node". Both paths keep working unchanged.
- **Architecture: amd64 only.** Decided. The Dockerfile compiles PHP extensions
  from source, so an arm64 build under QEMU would dominate release time, and
  Meridian has no arm64 target to serve. The app declares
  `"supported_architectures": ["amd64"]` so Runtipi hides it on hardware it
  cannot run on, rather than offering an install that fails at first start. This
  rules out Raspberry Pi and ARM mini-PC hosts; revisit only if a real
  deployment needs one.
- Nothing about this is Runtipi-specific. Published images also remove the
  `docker load` step from every ordinary node install, so the work is not
  spent only on this path.

## Path A — custom install, available today

Two variants, in increasing order of effort. Both need the images published.

### A1. Runtipi's "Add custom app" (no repository)

Runtipi's dashboard has a custom-app form that accepts a Compose file directly.
Paste the file from [`compose.runtipi.yaml`](#the-compose-file) below and fill the
environment in Runtipi's own editor. This is the right first move: it proves the
stack runs under Traefik before any repository exists, and it is the fastest way
to get a Meridian node onto a Runtipi box for QA.

### A2. A private Meridian app store

Create `KenEucker/meridian-appstore` from
[`runtipi/example-appstore`](https://github.com/runtipi/example-appstore). A
Runtipi user adds it under **Settings → App Stores → Add App Store** by pasting
the repository URL. The repository must be public for Runtipi to clone it; "custom"
here means "not the official store", not "not published".

Required layout — the folder name must equal the `id` in `config.json`:

```text
meridian-appstore/
└── apps/
    └── meridian/
        ├── config.json
        ├── docker-compose.yml
        └── metadata/
            ├── logo.jpg          # square, 1:1 — meridian-signal-camp-base-logo.png rescaled
            └── description.md
```

Whether that repository lives beside Meridian or inside it is a real choice.
Recommendation: **a separate repository**, because Runtipi clones the whole store
repo on every store refresh and this monorepo is large; keep the generator here
(`scripts/deploy/build-runtipi-app.mjs`) and have it write into a checkout of the
store repo, so the app files remain derived from the root version rather than
hand-maintained.

## The changes Meridian itself needs

Four, all small, all useful beyond Runtipi. **All four are implemented**:
items 1 through 3 by M19.27, item 4 by M19.28. The sections below are the
design they implement.

### 1. A proxied Caddyfile

Add `deploy/caddy/Caddyfile.proxied`: the same `meridian-app` snippet, served on
`:80` with `auto_https off`, for the case where something else terminates TLS.
It imports the existing snippet, so the headers and limits cannot drift.

```caddyfile
{
	auto_https off
	servers {
		trusted_proxies static private_ranges
	}
}

import /etc/caddy/meridian.snippet

:80 {
	import meridian-app
}
```

Add it to the `COPY` line and the `caddy adapt` loop in
`deploy/docker/Dockerfile` so a malformed version is a failed build, exactly as
the other three are. Note that `deploy/caddy/README.md` and the bundle validator
hold every *deployment* Caddyfile to HTTPS-only (technical spec 8.2); this one
is plain HTTP by design because TLS moved one hop out, so the validator was
taught the difference (`scripts/deploy/caddyfile-rules.mjs`) rather than being
loosened: a TLS-terminating configuration is still refused the moment it
serves plain HTTP.

### 2. Trusted proxies in Laravel

With Caddy terminating TLS, `php_fastcgi` sets `HTTPS=on` and PHP sees the real
scheme — which is why this has never been needed. Behind Traefik, Caddy speaks
plain HTTP and Laravel would generate `http://` URLs on an `https://` site:
mixed-content assets and magic-link login URLs that are wrong.

Add trusted-proxy configuration to `apps/server/bootstrap/app.php`, driven by a
new `MERIDIAN_TRUSTED_PROXIES` variable that is **empty by default** so every
existing deployment is unaffected. The Runtipi app sets it to `*`.

### 3. A derivable `APP_KEY`

Runtipi's `config.json` supports a `random` form field, which generates a value
at install time and stores it in the app's environment. Laravel needs
`base64:` + exactly 32 decoded bytes, and Runtipi's `min`/`encoding` semantics do
not obviously produce that — a `random` field of 32 characters encoded base64
decodes to 24 bytes, and Laravel would refuse it with an unhelpful cipher error.

Rather than guessing at another project's generator, derive the key from a
high-entropy seed in `deploy/docker/entrypoint.sh`, only when `APP_KEY` is unset:

```sh
if [ -z "${APP_KEY:-}" ] && [ -n "${MERIDIAN_APP_KEY_SEED:-}" ]; then
    APP_KEY="base64:$(php -r 'echo base64_encode(hash("sha256", getenv("MERIDIAN_APP_KEY_SEED"), true));')"
    export APP_KEY
fi
```

SHA-256 gives exactly 32 bytes for any seed length, so the key is always valid and
always the same for the same seed — which is what makes it survive a container
recreate, a Runtipi app update, and a restore from Runtipi's own backup. The
existing refusal is untouched: a node with neither `APP_KEY` nor a seed still
stops and says so.

The trade-off to state plainly in the app description: the seed lives in
Runtipi's app environment in cleartext, the same way `APP_KEY` lives in
`.env.deployment` in cleartext today. Anyone who can read either can decrypt this
node's sessions and encrypted configuration.

### 4. A generator and a validator

`scripts/deploy/build-runtipi-app.mjs`, writing `config.json`,
`docker-compose.yml`, and the store metadata from the root version, held by
`scripts/deploy/build-runtipi-app.spec.mjs` to declaring exactly the version
the repository is at, the amd64 architecture the images are built for, and a
stack whose worker and scheduler match the deployment stack's. The failure
this prevents is the one the bundle validator already exists to prevent: a
published app pinned to an image tag that no longer matches the code.
`corepack pnpm run runtipi:app` runs it; `docs/process/runtipi-distribution.md`
is the store-side runbook around it.

## <a id="the-compose-file"></a>The Compose file

Derived from `compose.deployment.yaml`, with the differences called out. This
listing and the `config.json` below are the design the generator implements —
`scripts/deploy/build-runtipi-app.mjs` is what actually writes both, with the
version lines derived from the root `package.json`, so generate rather than
copying from here.

```yaml
services:
  meridian-web:
    image: ghcr.io/keneucker/meridian-server-web:0.0.159
    restart: unless-stopped
    # Caddy on :80 with no TLS: Traefik terminates it one hop out.
    command: ["run", "--config", "/etc/caddy/Caddyfile.proxied", "--adapter", "caddyfile"]
    environment:
      MERIDIAN_SERVER_UPSTREAM: meridian-server:9000
    depends_on:
      meridian-server:
        condition: service_healthy
    # No `ports:`. Runtipi maps ${APP_PORT} to internal_port itself.
    x-runtipi:
      is_main: true
      internal_port: 80

  meridian-server:
    image: ghcr.io/keneucker/meridian-server:0.0.159
    restart: unless-stopped
    environment:
      MERIDIAN_CONTAINER_ROLE: web
      MERIDIAN_NODE_ROLE: ${MERIDIAN_NODE_ROLE}
      MERIDIAN_NODE_NAME: ${MERIDIAN_NODE_NAME}
      MERIDIAN_APP_KEY_SEED: ${MERIDIAN_APP_KEY_SEED}
      MERIDIAN_TRUSTED_PROXIES: "*"
      APP_NAME: Meridian
      APP_ENV: production
      APP_DEBUG: "false"
      # Runtipi injects both. Exposed with a domain this is https://<domain>,
      # which is what event mode requires (technical spec 8.2, 8.6).
      APP_URL: ${APP_PROTOCOL}://${APP_DOMAIN}
      DB_CONNECTION: pgsql
      DB_HOST: meridian-db
      DB_PORT: "5432"
      DB_DATABASE: meridian
      DB_USERNAME: meridian
      DB_PASSWORD: ${MERIDIAN_DB_PASSWORD}
      SESSION_DRIVER: database
      CACHE_STORE: database
      QUEUE_CONNECTION: database
      SESSION_SECURE_COOKIE: "true"
      MAIL_MAILER: smtp
      MAIL_HOST: ${MERIDIAN_MAIL_HOST}
      MAIL_PORT: ${MERIDIAN_MAIL_PORT}
      MAIL_USERNAME: ${MERIDIAN_MAIL_USERNAME}
      MAIL_PASSWORD: ${MERIDIAN_MAIL_PASSWORD}
      MAIL_FROM_ADDRESS: ${MERIDIAN_MAIL_FROM}
      MAIL_FROM_NAME: Meridian
      LOG_CHANNEL: stderr
      LOG_LEVEL: warning
    volumes:
      - ${APP_DATA_DIR}/storage:/var/www/meridian/apps/server/storage
    depends_on:
      meridian-db:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "php artisan db:show --quiet"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 60s

  # The worker and scheduler are not optional. Without the worker, magic-link
  # login mail is queued and never sent; without the scheduler, on-site never
  # pushes back to central.
  meridian-worker:
    image: ghcr.io/keneucker/meridian-server:0.0.159
    restart: unless-stopped
    command: ["php", "artisan", "queue:work", "--queue=notifications,default", "--tries=3", "--max-time=3600"]
    environment:
      MERIDIAN_CONTAINER_ROLE: worker
      # ...same environment as meridian-server
    volumes:
      - ${APP_DATA_DIR}/storage:/var/www/meridian/apps/server/storage
    depends_on:
      meridian-server:
        condition: service_healthy

  meridian-scheduler:
    image: ghcr.io/keneucker/meridian-server:0.0.159
    restart: unless-stopped
    command: ["php", "artisan", "schedule:work"]
    environment:
      MERIDIAN_CONTAINER_ROLE: scheduler
      # ...same environment as meridian-server
    volumes:
      - ${APP_DATA_DIR}/storage:/var/www/meridian/apps/server/storage
    depends_on:
      meridian-server:
        condition: service_healthy

  meridian-db:
    image: postgres:18
    restart: unless-stopped
    command:
      ["postgres", "-c", "wal_level=logical", "-c", "max_wal_senders=10", "-c", "max_replication_slots=10"]
    environment:
      POSTGRES_DB: meridian
      POSTGRES_USER: meridian
      POSTGRES_PASSWORD: ${MERIDIAN_DB_PASSWORD}
    volumes:
      - ${APP_DATA_DIR}/postgres:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U meridian -d meridian"]
      interval: 10s
      timeout: 3s
      retries: 10
      start_period: 20s

x-runtipi:
  schema_version: 2
```

## The `config.json`

```json
{
  "$schema": "../schema.json",
  "name": "Meridian",
  "id": "meridian",
  "available": true,
  "short_desc": "Open-source volunteer operations platform for events.",
  "author": "Ken Eucker",
  "port": 8390,
  "categories": ["utilities"],
  "description": "See metadata/description.md",
  "tipi_version": 1,
  "version": "0.0.159",
  "source": "https://github.com/KenEucker/meridian",
  "website": "https://github.com/KenEucker/meridian",
  "exposable": true,
  "force_expose": true,
  "dynamic_config": true,
  "supported_architectures": ["amd64"],
  "form_fields": [
    {
      "type": "random",
      "label": "Application key seed",
      "hint": "Generated once. Changing it makes every existing session and encrypted value unreadable.",
      "min": 64,
      "encoding": "hex",
      "env_variable": "MERIDIAN_APP_KEY_SEED",
      "required": true
    },
    {
      "type": "random",
      "label": "Database password",
      "min": 32,
      "encoding": "hex",
      "env_variable": "MERIDIAN_DB_PASSWORD",
      "required": true
    },
    {
      "type": "text",
      "label": "Node name",
      "placeholder": "Signal Camp Standalone",
      "env_variable": "MERIDIAN_NODE_NAME",
      "required": true
    },
    {
      "type": "text",
      "label": "Node role",
      "hint": "standalone, central, or onsite",
      "default": "standalone",
      "options": [
        { "label": "Standalone", "value": "standalone" },
        { "label": "Central", "value": "central" },
        { "label": "On-site (event node)", "value": "onsite" }
      ],
      "env_variable": "MERIDIAN_NODE_ROLE",
      "required": true
    },
    { "type": "fqdn", "label": "SMTP host", "env_variable": "MERIDIAN_MAIL_HOST", "required": true },
    { "type": "number", "label": "SMTP port", "default": "587", "env_variable": "MERIDIAN_MAIL_PORT", "required": true },
    { "type": "text", "label": "SMTP username", "env_variable": "MERIDIAN_MAIL_USERNAME", "required": false },
    { "type": "password", "label": "SMTP password", "env_variable": "MERIDIAN_MAIL_PASSWORD", "required": false },
    { "type": "email", "label": "Send mail from", "env_variable": "MERIDIAN_MAIL_FROM", "required": true }
  ]
}
```

`"force_expose": true` is the load-bearing line. Meridian in any non-development
role refuses to serve over plain HTTP (`EventModeGuard::evaluateHttps`), so an
app installable at `http://<ip>:8390` would install cleanly and then answer 503 to
everything. Requiring a domain makes `${APP_PROTOCOL}` resolve to `https` and the
node start. Runtipi's local-domain certificate satisfies the scheme check for a
LAN-only install; a browser will warn until the certificate is trusted, which is
the same trade `deploy/dns` already documents for on-site names.

SMTP is required rather than optional for the same reason: Meridian's login is a
mailed code, so a node that cannot send mail is a node nobody can sign in to. The
description must say so above the fold.

## Path B — publishing, after MVP

**The official Runtipi app store is closed to new applications.**
`runtipi/runtipi-appstore` states that it still takes updates but that no new apps
will be accepted and pull requests should be limited to bug fixes. So "publish
Meridian in the Runtipi app store" cannot mean a PR to that repository, and any
plan that assumes it will stall at the first step.

What publishing actually means, in descending order of value:

1. **Ship `KenEucker/meridian-appstore` as the supported store** and document the
   one-line install in the Meridian README: paste the URL into Runtipi's App
   Stores screen. This is the real distribution channel, and Path A2 already
   builds it. Post-MVP work here is release automation and support surface, not
   new mechanism: bump `version` and `tipi_version` on every Meridian release,
   keep `min_tipi_version` at the Runtipi version the app is tested against, and
   run a smoke install against a real Runtipi box before tagging.
2. **Get listed where Runtipi users look for stores.** Open a request in
   `runtipi/runtipi-appstore` Discussions, ask in the Runtipi Discord and forum,
   and submit to the community aggregator stores. This is the discovery half, and
   it costs an afternoon rather than an engineering cycle.
3. **Ask whether the policy has an exception.** Worth one Discussions post, not
   worth a plan. If the official store reopens, the app is already in the exact
   format it would need.

Post-MVP prerequisites before doing any of this publicly:

- The description must say amd64-only in its own line, not only in
  `supported_architectures`. Runtipi filters the store by architecture, but a
  person reading about Meridian somewhere else and then going looking for it
  should not have to discover the constraint by failing to find the app.
- A backup and restore story. Runtipi backs up `${APP_DATA_DIR}`, which covers
  the Postgres data directory and the storage volume, so the default is closer to
  correct than most apps — but a Postgres data directory copied while the server
  is running is not a backup. Document the `pg_dump` path, or add a pre-backup
  hook.
- An upgrade note. Migrations run automatically at boot (`entrypoint.sh`), so a
  Runtipi "Update" button is also a schema migration. The description must say
  "back up before updating" where a person will read it.
- A decision on the AGPL. Meridian is AGPL-3.0-or-later, and distributing a
  hosted-install path does not change the obligation, but the store listing should
  link the source clearly. `"source"` in `config.json` covers it.

## Open questions

Both are drawn from Runtipi's documentation rather than a running instance,
and both are closed by observed behavior during the first
[`QA-RUNTIPI-01`](../../docs/qa/QA-RUNTIPI-01-runtipi-app-install.md) run
(M19.29), which records the answers and the Runtipi version here.

- Whether Runtipi's `random` field with `encoding: "hex"` and `min: 64` produces
  64 hex characters or 64 bytes rendered as 128. It does not matter for the seed
  derivation above — any length works — which is precisely why the derivation is
  the recommended approach rather than feeding `random` straight into `APP_KEY`.
- Whether Runtipi's Traefik passes `X-Forwarded-Proto` through to a non-Traefik
  proxy in the app stack. If it does not, the trusted-proxy change in item 2 is
  insufficient and the proxied Caddyfile must set the FastCGI `HTTPS` parameter
  explicitly. Verify on a real box during Path A1.
- Whether `deploy/native` needs a counterpart. It does not: this is a packaging
  target for Docker hosts, not a change to how a node is deployed. The production
  mini-PC node stays on `deploy/native`.

## Suggested order

| # | Work | Blocks | Status |
|---|---|---|---|
| 1 | Push images to GHCR from the release workflow | everything | Done (M19.26) |
| 2 | `Caddyfile.proxied`, trusted proxies, `MERIDIAN_APP_KEY_SEED` | A1 | Done (M19.27) |
| 3 | Hand-install on a real Runtipi box via "Add custom app" (A1) | A2 | Open — [`QA-RUNTIPI-01`](../../docs/qa/QA-RUNTIPI-01-runtipi-app-install.md) (M19.29) |
| 4 | `meridian-appstore` repo + generator script (A2) | B | Generator and runbook done (M19.28, M19.29); the repository itself is created by following `docs/process/runtipi-distribution.md` |
| 5 | *(post-MVP)* backup/restore and upgrade docs | B | Stated in the generated `metadata/description.md`; deepen post-MVP |
| 6 | *(post-MVP)* discovery: Discussions, Discord, aggregator stores | — | Open, post-Alpha-1 |
