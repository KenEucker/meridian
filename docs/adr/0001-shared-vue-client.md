# ADR 0001: Shared Vue Client Across Web, Desktop, and Mobile

## Status

Accepted.

## Context

Meridian has web, desktop, and mobile install targets for the same operational
workflows. Maintaining separate UI implementations for those workflows would
create avoidable product drift, duplicate accessibility and offline behavior,
and make mobile-first design harder to preserve.

Laravel and Orchid remain the right fit for trusted administration, god-mode
data repair, and backend/API ownership. Electron and Capacitor remain the right
fit for packaging and platform capabilities.

## Decision

All non-admin user-facing product workflows live in one shared Vue client under
`apps/client`.

Laravel serves the built Vue client at `/` and continues to expose APIs and
backend services. Orchid remains the admin and god-mode surface under `/admin`.

`apps/mobile` is a Capacitor packaging project for iOS and Android. It contains
mobile packaging configuration and points at the shared client build output.

`apps/desktop` is an Electron packaging project. It serves a packaged local
copy of the shared client through a tiny local static server and keeps desktop
health/status UI in the Electron shell.

Platform differences belong behind shell/platform adapters. The shared Vue
client owns routes, screens, design system usage, accessibility, offline UX,
and operational workflow parity.

## Consequences

- Mobile-first responsive design is implemented once in `apps/client`.
- Web/PWA, Electron, and Capacitor must use the same route and component code
  for operational workflows.
- Electron and Capacitor may add platform capabilities, but they should not
  fork product UI.
- Laravel routes outside `/admin`, `/api`, auth/setup endpoints, and file
  delivery should trend toward serving or supporting the shared Vue client.
- Orchid should not receive new non-admin operational workflow UI.
- Version visibility belongs in the shared client's settings/about surface and
  in desktop health.
