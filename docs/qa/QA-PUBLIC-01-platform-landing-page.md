# QA-PUBLIC-01 Platform Landing Page

## Purpose

Verify that a visitor who has never heard of Meridian can read the landing
page at the deployment root and come away knowing what the platform does, see
each major feature area introduced with a screenshot of the seeded example
organization, understand the three platform offerings and how they differ, and
act on all of it by submitting an organization interest inquiry — with no real
organization's data anywhere on the surface and no payment or signup path
anywhere on the page.

This script covers what Milestone 20 added to the marketing surface: the
feature tour (PUBLIC-007), the Northwood screenshots (PUBLIC-008), the
offerings section (PUBLIC-009), and the interest form's placement at the end
of both. The marketing surface itself — availability by node, the interest
form's traps and rate limits, God Mode review, audit — is
[`QA-PUBLIC-02`](QA-PUBLIC-02-marketing-surface-and-organization-interest.md)'s,
and this script signs off nothing that one covers. The two are numbered in the
order the pages' concerns were built, not the order they are read.

## Requirements covered

- `PUBLIC-007`
- `PUBLIC-008`
- `PUBLIC-009`
- `PUBLIC-004` (no self-service organization creation, held to from this page)
- `BRAND-003` (Meridian identity on the marketing surface, unchanged by the
  new sections)
- Requirements section 7.25
- Technical spec section 8.7 (the surface at the deployment root only)
- UI implementation contract section 12.1 (`public.marketing`)
- `docs/process/marketing-screenshot-recapture.md` (the asset process this
  script reviews the output of)
- Meridian Alpha 1 tasks M20.1 through M20.6

## Environment

- Fresh checkout or task branch with dependencies installed.
- Laravel app migrated and development scenario seeded
  (`php artisan migrate:fresh --seed`).
- Server and client dev servers both running.
- No lock-clearing is needed: the development node serves the marketing
  surface even while the seed names an event on it. That exemption is exactly
  the development role — the deployable roles keep PUBLIC-006's refusal, which
  `QA-PUBLIC-02` section F verifies.
- A browser window holding no Meridian session.
- A screen reader, or the browser's accessibility tree inspector, for the alt
  text checks.

## Personas

- **Prospective organization**: somebody with no account, no session, and no
  prior knowledge of Meridian. The whole script is read as this person.

## Setup data

- Nothing beyond the seed. The screenshots under review are committed assets
  in `apps/client/public/assets/marketing/northwood/`, not live reads.

## Steps

### A. What the platform is

1. In a browser holding no session, open the client root (`/`). Confirm the
   landing page renders: the Meridian name and mark, a heading saying what the
   platform does, and a description of who it is for.
2. Read only the heading, the lede, and the "Who it is for" section, then
   answer aloud: what does this product do, and for whom? If those three
   pieces have not answered both questions, record that as a finding —
   PUBLIC-007's whole point is a visitor who arrives knowing nothing.
3. Confirm the page carries no organization's logo, name, or palette — the
   surface is Meridian's own (BRAND-003). `QA-PUBLIC-02` step 2 is the
   detailed version of this check; here it only needs to still be true with
   the new sections on the page.

### B. The feature tour

4. Scroll through "What Meridian does". Confirm there is one titled section
   per major feature area, each with a short description of what the feature
   does for an organization, a labelled list of micro-features, and a
   screenshot.
5. Confirm the tour states, before the first screenshot, that the organization
   pictured — Northwood Collective — is fictional and that no real
   organization's data appears.
6. On a feature with two sides (Intake and applications is one), use the
   perspective switch. Confirm the micro-features and the screenshot change
   together, that the switch reports its pressed state to assistive
   technology, and that both sides show a real surface — the organizer's
   review queue on one, the applicant's own form on the other. Confirm a
   one-sided feature offers no switch.
7. Work through every perspective of every feature and confirm each
   screenshot shows the surface its side describes, populated with Northwood
   scenario content, with no spinner, error, or empty state in frame.
8. Select a screenshot. Confirm it opens larger in an in-page viewer with its
   title, its perspective label, and its description; that Escape and the
   close control both close it; and that focus returns to the screenshot that
   was opened. Confirm the viewer is reachable and operable by keyboard
   alone.
9. **The PUBLIC-008 review.** Confirm nothing in any screenshot — every
   perspective of every feature — is a real organization's data: every
   person, department, event, document, incident, and equipment item visible
   is from the fictional seeded scenario. Anything recognizable as real fails
   the script.
10. With the screen reader or accessibility inspector, confirm every
    screenshot has alt text, and that the alt text describes what the image
    shows rather than repeating the section title.
11. Confirm each tour section's heading structure is real headings (the
    sections are navigable by heading level), not styled paragraphs.

### C. The offerings

12. Read "Three ways to run it". Confirm three offerings are described:
    self-hosting, free and open source; a hosted self-starter without support
    at a lower fee; and a fully hosted and managed offering with full support,
    including an on-site technician.
13. Answer aloud, from the page alone: which offering would an organization
    with its own ops team and hardware pick, and which would one with neither
    pick? If the descriptions have not made the difference plain, record it.
14. Confirm the offerings are described and nothing more: no prices, no
    payment method, no checkout, no plan-selection control, and no signup
    path. The only actionable thing near them is the link to the interest
    form.
15. Search the whole page for a route into self-service organization
    creation. There must be none — creating an organization stays a God Mode
    action (PUBLIC-004).

### D. Acting on it

16. From the end of the feature tour, follow the "tell us about your
    organization" link. Confirm it lands on the interest form on the same
    page.
17. Do the same from the offerings section.
18. Fill the form in and submit it as `QA-PUBLIC-02` section B describes.
    Confirm the thank-you replaces the form. The form's own behavior — what
    the submission creates, the traps, the limits — is covered there and is
    not re-verified here; what this script confirms is that a reader who just
    finished the tour and the offerings can act without hunting.

### E. The gate, in one sitting

19. Hand the page to somebody who has not seen Meridian before, with no
    explanation. Confirm they can say what the platform does, name a feature
    that matters to them from the tour, say how the three offerings differ,
    and find where to express interest — without leaving the page or asking
    you anything.

## Expected results

- The landing page at the deployment root explains the platform and
  introduces each major feature area with a description, labelled
  micro-features, and a Northwood screenshot (PUBLIC-007, PUBLIC-008) — from
  both sides, with a working perspective switch, wherever the product has a
  real second surface.
- The fictional-organization statement is on the page ahead of the
  screenshots, and no real organization's data appears in any of them.
- Every screenshot carries descriptive alt text.
- The three offerings are described, differ legibly, and offer no payment,
  billing, or signup path (PUBLIC-009), and no path anywhere on the page
  creates an organization (PUBLIC-004).
- The interest form is reachable from the tour and from the offerings, and
  submitting it works as `QA-PUBLIC-02` already established.
- Meridian identity, and only Meridian identity, is on the surface
  (BRAND-003).

## Evidence to capture

- Full-page screenshot of the landing page in a signed-out browser.
- The accessibility inspector showing alt text on at least two tour
  screenshots.
- A note recording the PUBLIC-008 review: who looked at the eight committed
  screenshots, when, and that nothing from a real organization appears.
- The step 19 reader's answers, roughly transcribed.

## Failure notes

- A screenshot showing a real organization's data is a stop-everything
  finding against PUBLIC-008: pull the asset, recapture from the seeded
  scenario per `docs/process/marketing-screenshot-recapture.md`, and review
  how it got in.
- A payment, billing, or signup control anywhere on the page is PUBLIC-009's
  explicit exclusion; record it and stop.
- A screenshot rendering as a broken image means the committed asset and the
  tour catalogue disagree; the client test suite's asset presence check
  should have caught it, so a broken image here means the build under test
  and the tested tree differ.
- If the step 19 reader cannot answer one of the four questions, the copy has
  failed PUBLIC-007 or PUBLIC-009 even though every element is present;
  record what they could not answer.
