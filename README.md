![Meridian logo](meridian.png)

# Meridian

Meridian is an open-source volunteer operations platform for events.

It is designed for organizations that recruit, approve, coordinate, schedule, credential, track, and report on volunteer work across events and departments. Meridian models volunteer operations, not employment, payroll, HR, or personnel management.

Meridian is currently in early project scaffolding. The repository contains requirements, technical direction, and development-process guardrails. Product behavior has not been implemented yet.

## What Meridian Is For

Meridian is intended to support:

- organizations, events, departments, teams, and volunteer membership;
- volunteer status, training, waivers, shift eligibility, and credentials;
- planned shifts and actual hours worked;
- field reports, incidents, audit history, and operational records;
- offline-capable field workflows for event environments with limited connectivity;
- central and on-site node operation for Alpha 1.

## Source Documents

The current source-of-truth documents are:

- [Requirements document](docs/meridian-requirements-document.md)
- [Technical specification](docs/meridian-technical-spec.md)
- [Technology baseline](docs/meridian-technology-baseline.md)
- [Development process](docs/process/meridian-development-process.md)
- [Versioning strategy](docs/process/versioning-strategy.md)

Start with the development process before opening issues or pull requests. It defines how work should move from requirement to implementation, review, automated checks, and human QA.
Before adding, replacing, or upgrading runtimes, packages, libraries, services, wrappers, test tools, or package managers, read the technology baseline and ask for human approval if the change is not already approved there.

## Development Process

Meridian uses a traceable development workflow:

- every meaningful change references a requirement ID or technical spec section;
- pull requests must include traceability, acceptance criteria, tests, and human QA;
- QA scenarios live under [docs/qa](docs/qa);
- the traceability matrix lives at [docs/process/traceability-matrix.md](docs/process/traceability-matrix.md);
- commit messages and PR titles use [Conventional Commits](docs/process/conventional-commits.md).

## Repository Layout

Meridian uses a monorepo layout aligned with the Alpha 1 technical specification:

```text
apps/
  client/        Shared Vue product client for Admin, Field, and Kiosk artifacts
  server/        Laravel, API, Meridian Admin serving, and Orchid repair tooling
  mobile/        Capacitor iOS/Android packaging wrapper
  desktop/       Electron on-site workstation wrapper
packages/
  shared-types/  Shared TypeScript types
  openapi-client/ Generated TypeScript API client
deploy/
  docker/        Docker and Docker Compose configuration
  caddy/         Reverse proxy and certificate configuration
  dns/           DNS configuration for on-site deployments
```

`apps/client` contains the shared Vue product client that builds Meridian Admin,
Meridian Field, and Meridian Kiosk artifacts. `apps/server` contains the
Laravel backend/API, serves Meridian Admin, and exposes Orchid God Mode / repair
tooling (see
[apps/server/README.md](apps/server/README.md)), `apps/mobile` contains the
Capacitor iOS/Android packaging wrapper (see
[apps/mobile/README.md](apps/mobile/README.md)), and `apps/kiosk` contains
the Electron on-site wrapper shell (see
[apps/kiosk/README.md](apps/kiosk/README.md)). The managed PostgreSQL
database service lives under [deploy/docker](deploy/docker/README.md). The
`caddy` and `dns` deployment directories are placeholders until their later
Alpha 1 tasks add deployment behavior.

## Developer Boot Path

Meridian is in early scaffolding. The `apps/server` Laravel scaffold can boot
and run its test suite, the `apps/client` shared Vue client builds and runs its
smoke tests, `apps/mobile` validates the Capacitor packaging configuration,
and the `apps/kiosk` Electron wrapper shell builds and runs its unit tests.
A managed PostgreSQL database service ([deploy/docker](deploy/docker/README.md)),
PostgreSQL development configuration, and seed data are present, and the
deployment bundle that stands up a real node — server image, reverse proxy, queue
worker, scheduler, and DNS templates — is in [deploy/](deploy/README.md). See
[Deploying a Node](#deploying-a-node) below; the rest of this section is the
developer path and does not stand up a deployment.

Normal product UI is built in the shared Vue client. Orchid is reserved for God
Mode / repair tooling, configuration override, and dangerous administration.

For the repository as a whole, a fresh checkout should be able to run the process validators. The server app additionally supports the Laravel boot/test commands documented in [apps/server/README.md](apps/server/README.md).

### Prerequisites

Use the approved development baseline:

- Node.js 24.x with Corepack enabled.
- pnpm 11.x through Corepack, as declared by `package.json`.
- Python 3.10 or newer for process validators.
- Git Bash on Windows, or any POSIX shell on Linux/macOS, for `scripts/process/check.sh`.

On a fresh checkout, enable Corepack if it is not already enabled:

```bash
corepack enable
```

Install the current Node workspace dependencies from the lockfile:

```bash
corepack pnpm install --frozen-lockfile
```

### Local App Setup

Configure the ignored local env files for Laravel and every shared-client Vite
mode:

```bash
corepack pnpm run env:local
```

When a local PostgreSQL database is reachable and server dependencies are
installed, run the full idempotent setup instead:

```bash
corepack pnpm run setup:local
```

`setup:local` also ensures Laravel has an `APP_KEY`, clears cached
configuration, runs migrations, and seeds the local Field fixture used by Field
Report command upload QA. `server:setup` is an alias for this same path.

It configures no API credential. Client applications authenticate with a
device-bound bearer token, so a developer signs in to the client at `/login` as
the seeded fixture user (`local-field@meridian.test`) and reads the login
code out of the mail log with `corepack pnpm run server:logs -- --filter "login
code"`.

### Quick Local Check

Run the process checks before opening a pull request:

```bash
corepack pnpm run check
```

This runs the repository process validators through the Corepack-managed pnpm version declared in `package.json`.

### Fresh-Checkout QA Check

For the full fresh-checkout QA path, run the POSIX process script from Git Bash on Windows or any POSIX shell on Linux/macOS:

```bash
scripts/process/check.sh
```

The script validates the process scaffold, validates the `apps/server` Composer project and runs its Laravel tests when server dependencies are installed, skips root Composer checks until a root `composer.json` exists, installs Node dependencies when `package.json` exists, and runs only the Node scripts that are currently defined.

Individual checks are also available:

```bash
corepack pnpm run process:traceability
corepack pnpm run process:qa
corepack pnpm run process:repo
corepack pnpm run process:pr-template
corepack pnpm run commit:check -- --message "docs(process): update README"
```

The validators use the Python standard library. Composer and product-service checks are designed to become active as later Alpha 1 tasks add those project files and bootable services.

## Deploying a Node

The section above is the developer path: `php artisan serve`, Vite, and a local
database. It does not stand up a central, on-site, or standalone node, and those
need a different set of steps.

One Compose install serves every deployment role. The role is configuration —
`MERIDIAN_NODE_ROLE` — rather than a different stack, so the path below is the
same for all three.

### Prerequisites

- Docker with Docker Compose, on the machine that will be the node.
- A hostname the node's devices can resolve, and a certificate for it. An event
  node's certificate must be obtained **before** the event: a field network has
  no route to a certificate authority.
- Node.js and pnpm, for the `deploy:*` scripts that wrap Compose.

### First run, in order

```bash
cp deploy/docker/.env.deployment.example deploy/docker/.env.deployment
corepack pnpm run deploy:build
```

1. **Set `MERIDIAN_IMAGE_TAG`** in `deploy/docker/.env.deployment` to the version
   `deploy:build` printed. The Compose file requires it, so nothing else runs
   until it is set.
2. **Generate this node's `APP_KEY`** and set it. It needs the image built above,
   which is why it cannot be done while first editing the file:

   ```bash
   docker compose --env-file deploy/docker/.env.deployment \
     -f deploy/docker/compose.deployment.yaml \
     run --rm server php artisan key:generate --show
   ```

   Generate one per node. Never copy a key, or a whole environment file, between
   nodes.
3. **Fill in the remaining `CHANGE ME` values**: node role, `APP_URL` and
   `MERIDIAN_SITE_ADDRESS`, `DB_PASSWORD`, mail credentials, and — for an event
   node — `MERIDIAN_CADDYFILE`, `MERIDIAN_TLS_CERTIFICATE`, and
   `MERIDIAN_TLS_KEY`.
4. **Start the stack.** Migrations run automatically; back the database up first
   on an existing node.

   ```bash
   corepack pnpm run deploy:up
   corepack pnpm run deploy:ps
   ```

   A node still holding a sample secret stops here instead of serving and names
   the variable in `corepack pnpm run deploy:logs`. That is the production
   safeguard working. `php artisan meridian:secrets` on the node lists everything
   outstanding, and never prints a value.
5. **Open the node in a browser.** With no identity it redirects to first-run
   setup, where it gets its name, its role, and its signing keypair.
6. **Pair an on-site node with central.** A separate step, done from both sides:
   central issues a one-time token, the on-site node redeems it.
7. **Open the God Mode console.** The landing screen lists anything still
   outstanding for this deployment.

### Where the detail lives

| Document | What it covers |
|---|---|
| [deploy/README.md](deploy/README.md) | The bundle itself: what each file is, the `deploy:*` scripts, event-node specifics, backups. |
| [Deployment](docs/technician/deployment.md) | The operational walkthrough, with what every value means, the secret safeguards, and a pre-event checklist. |
| [Node setup and pairing](docs/technician/node-setup-and-pairing.md) | First-run setup and central pairing, from both sides. |
| [Configuration](docs/technician/configuration.md) | Config sources and precedence, event mode, and the override catalogue. |
| [deploy/dns/README.md](deploy/dns/README.md) | Name resolution on an event network. |

## License

Meridian is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE).
