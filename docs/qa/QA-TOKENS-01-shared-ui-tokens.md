# QA-TOKENS-01: Shared UI Tokens Baseline

## Purpose

Verify that the shared Meridian semantic UI design tokens (M2.5) are defined
once and consumed by both the shared client (Vue) surface and the admin (Orchid /
server) surface. This is a visual smoke test of the token skeleton; no domain
workflows, authentication, or offline behavior are expected at this stage.

## Requirements covered

- Technical spec: Section 3.2 Shared client application
- Technical spec: Section 3.1 Server/admin application
- UI Implementation Contract: Section 10 Design Token Contract (canonical
  `--m-*` token names) and Section 9.9 Dashboard Attention Scale
- Component Library Specification: Section 3 Token Requirements
- Style Guide: Sections 4 (color), 5 (typography), 10 (spacing/radius),
  11 (status), 13 (accessibility)

## Environment

- Fresh local checkout.
- Development environment.
- Node.js 24 LTS and pnpm 11.x available via Corepack.
- PHP 8.5 and the Laravel server app installed (for the admin surface check).

## Personas

- Developer
- Human reviewer

## Setup data

- No seed data is required. The token baseline has no authentication or domain
  data.

## Steps

1. From the repository root, install workspace dependencies:
   `corepack pnpm install`.
2. Run the token contract smoke test: `corepack pnpm run tokens:test`.
3. Run the shared client smoke test: `corepack pnpm --filter @meridian/client run test`.
4. Confirm the package and admin copies are in sync: run
   `corepack pnpm run tokens:sync` and confirm
   `apps/server/public/css/meridian-tokens.css` has no pending git changes.
5. Confirm the platform palette tokens are exactly `#475157`, `#6B7562`,
   `#A58667`, and `#CC792F`, and that action/status/attention/department
   accent tokens resolve to those colors rather than ad hoc raw hex values.
6. Start the shared client dev server: `corepack pnpm run client:dev` and open the
   dev URL (for example `http://localhost:5173/`). Confirm the home surface loads
   on the warm canvas surface with token-driven text and shell styling.
7. Toggle the OS appearance between light and dark (or set
   `document.documentElement.dataset.theme = "dark"` in the browser console)
   and confirm the shared client surface switches to the dark token values.
8. Start the server (`corepack pnpm run server:dev`) and open `/`. Confirm the
   server landing page renders with the shared tokens (warm canvas in light
   mode, dark surface in dark mode).
9. Open the admin login page at `/admin/login` and confirm (via view source or
   dev tools) that `css/meridian-tokens.css` is linked in the document head.

## Expected results

- `corepack pnpm run tokens:test` passes: every canonical `--m-*` token from
  contract section 10.1 is defined with a non-empty light value, dark mode
  redefines the same names, and the client/admin copies are byte-identical.
- Platform-governed action, status, attention, focus, and department accent
  tokens resolve to one of the four logo colors.
- `corepack pnpm --filter @meridian/client run test` passes, including the
  shared client token-wiring checks.
- `tokens:sync` produces no git diff (package and server copies match).
- The shared client and the server landing page both render using the shared
  tokens and respond to light/dark mode.
- The admin login page head links `css/meridian-tokens.css`.

## Evidence to capture

- Terminal output from `tokens:test` and the shared client smoke test.
- Screenshots of the shared client home in light and dark mode.
- Screenshot or view-source snippet showing `css/meridian-tokens.css` linked on
  the admin login page.

## Failure notes

Record the failed command, exact error text, operating system, Node.js, pnpm,
and PHP versions, whether dependencies installed from the committed lockfile,
and whether `tokens:sync` reported drift between the package and server copies.
