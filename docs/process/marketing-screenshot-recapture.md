# Marketing Screenshot Recapture

**Project:** Meridian
**Document type:** Developer process guide
**Owning task:** M20.2 (PUBLIC-008)
**Source documents:**

- `docs/meridian-requirements-document.md` (PUBLIC-007, PUBLIC-008)
- `docs/process/developer-testing-process.md` (the Northwood scenario and its logins)
- `apps/client/src/marketing/marketingTour.ts` (the feature tour catalogue)
- `scripts/marketing/capture-northwood-screenshots.mjs` (the capture script)

---

## 1. What these screenshots are

The platform landing page introduces each major feature area with a screenshot
(PUBLIC-007), and every screenshot is captured from the seeded Northwood
development scenario — a fictional organization, fictional people, fictional
events (PUBLIC-008). The images are **committed static assets** in
`apps/client/public/assets/marketing/northwood/`, one per feature tour entry,
named `<tour id>.webp`. Nothing on the marketing surface reads live data.

They therefore go stale on purpose: a UI change does not change them until
somebody recaptures. That is the trade PUBLIC-008 makes — a page that can
never leak a real organization's records, at the price of this document.

## 2. When to recapture

- A featured surface changed visibly (layout, navigation, copy, theme).
- The feature tour in `marketingTour.ts` gained or changed an entry — the
  asset presence test in `marketingTour.spec.ts` fails until the new image
  exists.
- The Northwood seed changed what a featured surface shows.

## 3. How

Everything runs against the local development environment:

```bash
corepack pnpm run db:up
corepack pnpm run server:migrate:seed
```

Re-seeding right before capturing matters more here than usual: the scenario's
schedule is anchored to the seeding moment, and screenshots of a shift board
whose "running now" shift ended yesterday photograph worse than they test.

Then, with the server (`php apps/server/artisan serve`, port 8000) and the
**admin-mode** client (`corepack pnpm run client:dev:admin`, port 5173 — the
only origin the server's CORS policy allows) both running:

```bash
node scripts/marketing/capture-northwood-screenshots.mjs
```

Naming ids recaptures only those (`… capture-northwood-screenshots.mjs
equipment readiness`). The script signs in as the seeded persona each surface
belongs to (the login-code endpoints are rate limited; the script waits the
limits out, so a full run takes a few minutes), drives a headless Chrome at a
1280×800 viewport at 2× scale, waits for each surface to finish rendering,
and writes WebP images into the asset directory. `CHROME_BIN`,
`MERIDIAN_SERVER_URL`, and `MERIDIAN_CLIENT_URL` override the defaults.

Which persona and route each image uses is defined in the script's `CAPTURES`
table, beside the tour entry ids. Change the featured surface there, not by
hand-cropping.

## 4. Review before committing — the human step

Automated capture does not remove the review PUBLIC-008 exists for. Before
committing recaptured images, look at every one and confirm:

- **No real organization's data appears.** The only acceptable content is the
  fictional Northwood scenario. If a capture ran against any other database,
  discard it.
- Every image shows the surface working — no spinners, no empty states, no
  error text, no blank panels.
- Nothing in frame is content you would not put in front of a prospective
  organization (the seeded incident list, for example, includes realistic
  incident types; keeping them is a deliberate choice about representing the
  product honestly, not an accident).
- The image matches its alt text in `marketingTour.ts` — the alt text is the
  screenshot for a reader who cannot see it, and a recapture that changes what
  is shown changes the sentence too.

Then run the client tests (`corepack pnpm --filter @meridian/client test`),
which assert every tour entry's asset exists, and commit the images with the
change that made them stale.
