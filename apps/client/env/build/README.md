# Build environment directory

Intentionally holds no `.env` files.

`vite.config.ts` points `envDir` here for `vite build`, so a production build
loads no env file at all and takes its `VITE_*` values only from variables
already exported when the build ran — which is how CI and a deployment bake in
their own configuration, and which a file generated for local development
cannot reach.

The directory exists because `dev:field` and `build:field` are the same Vite
mode. Without it, the `apps/client/.env.meridian-field.local` that
`corepack pnpm run env:local` writes for the dev server is also compiled into
the packaged Field app, where its `VITE_MERIDIAN_API_BASE_URL` becomes the
`build` tier in `src/app/nodeConnection.ts` and outranks the on-site
convention — pointing a phone at the developer's laptop instead of
`meridian.home.arpa` (technical spec 8.4).

Do not add env files here. A value every build should carry belongs in the
build environment, and a value one developer needs belongs in the ignored
`.env.*.local` files in `apps/client`, which the dev server still reads.
