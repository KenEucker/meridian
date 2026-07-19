# QA-SHELL-01: Shared Client Shell Loads

## Purpose

Verify that the Meridian shared Vue client shell (M2.1) builds and loads:
the app shell renders, the placeholder Home surface is shown at `/`, and an
unknown path shows the placeholder Not Found surface. No domain workflows,
authentication, or offline behavior are expected at this stage.

## Requirements covered

- Technical spec: Section 3.2 Shared client application
- UI Implementation Contract: Section 3 Alpha 1 Technical Contract
- UI Implementation Contract: Section 4 Global UI Rules (app shell; no
  persistent left sidebar as primary navigation)

## Environment

- Fresh local checkout.
- Development environment.
- Node.js 24 LTS and pnpm 11.x available via Corepack.

## Personas

- Developer
- Human reviewer

## Setup data

- No seed data is required. The shell has no authentication or domain data.

## Steps

1. From the repository root, install workspace dependencies:
   `corepack pnpm install`.
2. Run the automated smoke test: `corepack pnpm --filter @meridian/client run test`.
3. Build the production bundle: `corepack pnpm --filter @meridian/client run build`.
4. Start the dev server: `corepack pnpm --filter @meridian/client run dev`.
5. In a browser, open the dev server URL (for example `http://localhost:5173/`)
   and confirm the home placeholder loads inside the app shell.
6. Navigate to an unknown path such as `http://localhost:5173/nope` and confirm
   the Not Found placeholder is shown with a link back home.

## Expected results

- `corepack pnpm install` completes without errors.
- The smoke test passes (app shell renders; Home and Not Found placeholders).
- `corepack pnpm --filter @meridian/client run build` produces a production
  build under `apps/client/dist` without errors.
- The dev server serves the app shell with a top bar showing "Meridian Field".
- `/` shows the Home placeholder heading "Meridian Field".
- An unknown path shows the "Page not found" placeholder with a working link
  back home.

## Evidence to capture

- Terminal output from the smoke test and the production build.
- A screenshot of the Home placeholder inside the app shell.
- A screenshot of the Not Found placeholder.

## Failure notes

Record the failed command, exact error text, operating system, Node.js and
pnpm versions, and whether dependencies installed from the committed lockfile.
