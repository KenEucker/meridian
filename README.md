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
  server/        Laravel, Orchid, and API application
  mobile/        Vue and Capacitor field application
  desktop/       Electron on-site workstation wrapper
packages/
  shared-types/  Shared TypeScript types
  openapi-client/ Generated TypeScript API client
deploy/
  docker/        Docker and Docker Compose configuration
  caddy/         Reverse proxy and certificate configuration
  powersync/     PowerSync service configuration
  dns/           DNS configuration for on-site deployments
```

`apps/server` contains a Laravel framework scaffold (see [apps/server/README.md](apps/server/README.md)), `apps/mobile` contains the Vue/Capacitor field app shell (see [apps/mobile/README.md](apps/mobile/README.md)), and `apps/desktop` contains the Electron on-site wrapper shell (see [apps/desktop/README.md](apps/desktop/README.md)). The `packages/` and `deploy/` directories are placeholders until their later Alpha 1 tasks add application or deployment behavior.

## Developer Boot Path

Meridian is in early scaffolding. The `apps/server` Laravel scaffold can boot and run its default test suite, the `apps/mobile` Vue field app shell builds and runs its smoke tests, and the `apps/desktop` Electron wrapper shell builds and runs its unit tests, but none contain Meridian product behavior yet. The Docker Compose stack, PostgreSQL configuration, seed data, and product services are added in later Alpha 1 tasks.

While the Orchid Admin should be comprehensive for features and data management, UI components for screens will be targeted for the majority of users.

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

## License

Meridian is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE).
