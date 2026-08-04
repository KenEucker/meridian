# QA-PUBLIC-02 Marketing Surface and Organization Interest

## Purpose

Verify that somebody who has never heard of Meridian can arrive at the deployment root, read what it is, and tell the people who run it about their organization — and that doing so creates nothing, replaces nothing, and cannot be automated at volume. This script covers the surface at the deployment root, Meridian identity on it, the interest form and the inquiry it creates, God Mode review, the two rate limits, the two automated-submission traps, the audit trail, and the nodes that do not serve any of it.

The landing page's feature tour, its Northwood screenshots, and its descriptions of the three platform offerings belong to Milestone 20 and are not in scope here. `QA-PUBLIC-01` is reserved for that page's own script, which M20.6 adds; this one is numbered second because the surface it covers was built first.

## Requirements covered

- `PUBLIC-001`
- `PUBLIC-002`
- `PUBLIC-003`
- `PUBLIC-004`
- `PUBLIC-005`
- `PUBLIC-006`
- `BRAND-003` (Meridian identity on a surface with no organization)
- Requirements section 7.25
- Technical spec section 8.7 (the deployment-root surface and where it does not render)
- Data/API spec section 10.22 (`organization_inquiries`)
- Meridian Alpha 1 task M18.23

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and development scenario seeded (`php artisan migrate:fresh --seed`).
- Development web server running.
- A browser window with no Meridian session, and a second one signed in — a private window is the easy way to hold both.

## Personas

- **Prospective organization**: somebody with no account and no session, reading the deployment root for the first time.
- **Signed-in operator**: any seeded persona, for the root-address behavior.
- **God Mode user**: Gwen Godmode, for the console review and the audit trail.

## Setup data

- Nothing is required. The interest form creates its own record.
- Note the configured values if they have been changed from their defaults: `MERIDIAN_ORGANIZATION_INTEREST_PER_EMAIL_PER_HOUR` (3), `MERIDIAN_ORGANIZATION_INTEREST_PER_CLIENT_PER_HOUR` (10), `MERIDIAN_ORGANIZATION_INTEREST_MINIMUM_SECONDS` (3).

## Steps

### A. The surface at the deployment root

1. In a browser holding no Meridian session, open the deployment root (`/`). Confirm the marketing surface renders: what Meridian is, who it is for, and the interest form.
2. Confirm the page carries the Meridian mark and the Meridian name, the Meridian footer with the license and build version, and no organization logo, name, or palette anywhere on it.
3. View source and confirm the only stylesheets are `/css/meridian-tokens.css` and `/css/meridian-surface.css`. Confirm no `branding/{organization}/tokens.css` stylesheet is linked.
4. Open `/platform`. Confirm the same page renders at its own address.
5. In the signed-in browser, open the deployment root. Confirm the Meridian client application loads, exactly as it did before this change.
6. From the signed-in browser, open `/platform`. Confirm the marketing surface renders there rather than being redirected away.

### B. Submitting interest

7. Back in the signed-out browser, fill in the interest form on `/`: organization name, your name, your email address, and a couple of sentences about what the organization runs. Submit.
8. Confirm the confirmation page names the organization and states plainly that nothing has been created yet — no account, no organization, no sign-up.
9. Refresh the confirmation page. Confirm it does not re-submit the form and returns you to the marketing surface.
10. Submit again with a missing name and a malformed email address. Confirm both fields are reported, the page keeps what you typed, and no inquiry is created.

### C. God Mode review

11. Sign in as Gwen Godmode and open **Organization Inquiries** in the console sidebar, under Operations.
12. Confirm the submission from step 7 is listed, with its organization name, contact, address, status **New**, and submission time.
13. Open it. Confirm the free text you wrote is shown in full, that every field is read-only, and that the contact address is labelled as unverified.
14. Confirm there is **no** action anywhere on the screen that creates an organization from the inquiry. Creating one stays a deliberate step on the Organizations screen.
15. Write a review note and press **Mark reviewed**. Confirm the status becomes **Reviewed**, and that your name and the time appear as the last decision.
16. Press **Close**, confirm the inquiry is still listed rather than deleted, then **Reopen** it and confirm it returns to **Reviewed**.
17. Sign in as a console user who holds `platform.organizations` but not `platform.organization-inquiries`. Confirm the sidebar item is absent and that opening the inquiries URL directly is refused.

### D. Rate limits and automated-submission traps

18. From the signed-out browser, submit the form four times with the same contact address, waiting a few seconds each time. Confirm the fourth is refused with a message about too many submissions, and that only three inquiries exist.
19. Wait for the hour to pass, or clear the cache (`php artisan cache:clear`), then submit repeatedly with a different address each time. Confirm the eleventh is refused and only ten inquiries exist.
20. Clear the cache again. Load `/`, then submit the form within a second or two of the page appearing — the browser's developer console can post the form for you. Confirm the submission comes back to the form with your input still in it and a message asking you to check it over, and that no inquiry was created.
21. Using developer tools, set a value on the hidden `organization_reference_code` field and submit an otherwise valid form. Confirm the response is byte-for-byte the confirmation page a real submission gets, and that **no** inquiry was created.
22. Confirm at no point were you asked to solve a challenge, identify images, or prove you are human.

### E. Audit

23. As Gwen Godmode, read the audit trail for entity type `organization_inquiry`. Confirm:
    - each recorded submission has an `organization_inquiry.submitted` entry naming the organization, contact, address, and status, with no organization scope and no actor;
    - the free-text description is **not** in the audit payload;
    - the trapped submission from step 21 has an `organization_inquiry.discarded` entry with the reason and nothing else;
    - each console decision has an `organization_inquiry.reviewed` entry naming you and carrying the before and after status.

### F. Nodes that do not serve it

24. Configure the node as on-site (`MERIDIAN_NODE_ROLE=onsite` in `.env`, or set the node role in the console), and restart the server.
25. In the signed-out browser, open the deployment root. Confirm the client application is served rather than the marketing surface.
26. Open `/platform`. Confirm a 404 — the page is not there, not withheld.
27. Return the node role to `development`, then lock the node to an event by setting its `event_id` (`php artisan tinker --execute='App\Models\Node::query()->where("is_local", true)->update(["event_id" => App\Models\Event::query()->value("id")]);'`).
28. Confirm the deployment root again serves the client application and `/platform` is again a 404.
29. Clear the lock (`... update(["event_id" => null])`) and confirm the marketing surface returns.

## Expected results

- The marketing surface is at the deployment root for a visitor with no session, and carries Meridian identity with no organization branding profile resolved.
- A signed-in browser reaches the client application at the root as before.
- An interest submission creates one inquiry and no organization, user, or staff record.
- The inquiry is reviewable in God Mode by a holder of `platform.organization-inquiries` and by nobody else, and no console path turns one into an organization.
- Both rate limits refuse at their configured counts.
- A submission that fills the hidden field is discarded with an indistinguishable response; a submission that arrives too fast is returned to the visitor intact.
- No challenge is ever presented.
- Submission, discard, and review are all audited.
- An on-site node and an event-locked node serve the client application at the root and 404 the marketing routes.

## Evidence to capture

- Screenshot of the marketing surface at `/` in a signed-out browser.
- Page source excerpt showing the two Meridian stylesheets and no branding stylesheet.
- Screenshot of the God Mode inquiry list and detail screens.
- Screenshot or copy of the refusal message at the rate limit.
- Audit trail rows for `organization_inquiry.submitted`, `organization_inquiry.discarded`, and `organization_inquiry.reviewed`.
- The 404 from `/platform` on the on-site node and on the event-locked node.

## Failure notes

- If an organization logo, name, or palette appears anywhere on the marketing surface, stop: that is BRAND-003 and PUBLIC-001 both.
- If any inquiry row carries an organization, user, or staff reference, stop: PUBLIC-003 is not met.
- If a console action creates an organization, stop: PUBLIC-004 is not met.
- If a legitimate, carefully typed submission is silently dropped, record it — the timing floor is meant to return work rather than eat it, and the configured value may be too high for the deployment.
