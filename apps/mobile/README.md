# Meridian Mobile

The Meridian Vue field/mobile application shell.

This is the `M2.1` app shell: a Vue 3 + Vite application with placeholder
routing and no domain workflows. Capacitor packaging (`M2.2`), shared semantic
UI tokens (`M2.5`), authentication, offline/PowerSync state, and product
screens arrive with their owning Alpha 1 milestones.

## Source references

- Technical spec section 3.2 Mobile/field application.
- UI Implementation Contract sections 3 (Alpha 1 Technical Contract), 4 (Global
  UI Rules), and 12 (Route and Screen Inventory).

## Stack

- Vue 3 (`vue`, `vue-router`).
- Vite 8 build tooling with `@vitejs/plugin-vue`.
- TypeScript with `vue-tsc` for type checking.
- Vitest with `@vue/test-utils` and `jsdom` for the smoke test.

These versions follow `docs/meridian-technology-baseline.md`.

## Local development

Install workspace dependencies from the repository root:

```bash
corepack pnpm install
```

Run the field app commands from the repository root via the workspace filter:

```bash
# Start the dev server
corepack pnpm --filter @meridian/mobile run dev

# Type check
corepack pnpm --filter @meridian/mobile run typecheck

# Production build
corepack pnpm --filter @meridian/mobile run build

# Smoke test
corepack pnpm --filter @meridian/mobile run test
```

The root `build`, `typecheck`, and `test` scripts delegate to this app so the
existing process CI runs them automatically.

## Routes

| Route name | Path | Purpose |
|---|---|---|
| `home` | `/` | Placeholder field home surface |
| `not-found` | catch-all | Placeholder not-found surface |

Domain routes from the UI Implementation Contract route inventory are added
with their owning milestones.
