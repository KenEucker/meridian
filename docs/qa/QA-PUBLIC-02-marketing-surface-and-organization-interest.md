# QA-PUBLIC-02 Marketing Surface and Organization Interest

## Purpose

Verify that somebody who has never heard of Meridian can arrive at the deployment root, read what it is, and tell the people who run it about their organization — and that doing so creates nothing, replaces nothing, and cannot be automated at volume. This script covers the surface at the client's root route, Meridian identity on it, the interest form and the inquiry it creates, God Mode review, the two rate limits, the two automated-submission traps, the audit trail, and the nodes that do not serve any of it.

The marketing surface is a client application view. Nothing about it is server-rendered: the node owns two public endpoints and the review screens, and everything the visitor reads is rendered by the client.

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
- UI implementation contract section 12.1 (`public.marketing`)
- Meridian Alpha 1 task M18.23

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and development scenario seeded (`php artisan migrate:fresh --seed`).
- Server and client dev servers both running.
- **Important:** the seeded development node is locked to an event, and PUBLIC-006 means the marketing surface is correctly absent while it is. Clear the lock before section A and restore it in section F:

```bash
php artisan tinker --execute='App\Models\Node::query()->where("is_local", true)->update(["event_id" => null]);'
```

- A browser window with no Meridian session, and a second one signed in — a private window is the easy way to hold both.

## Personas

- **Prospective organization**: somebody with no account and no session, opening the client for the first time.
- **Signed-in operator**: any seeded persona, for the root-route behavior.
- **God Mode user**: Gwen Godmode, for the console review and the audit trail.

## Setup data

- Nothing is required. The interest form creates its own record.
- Note the configured values if they have been changed from their defaults: `MERIDIAN_ORGANIZATION_INTEREST_PER_EMAIL_PER_HOUR` (3), `MERIDIAN_ORGANIZATION_INTEREST_PER_CLIENT_PER_HOUR` (10), `MERIDIAN_ORGANIZATION_INTEREST_MINIMUM_SECONDS` (3).

## Steps

### A. The surface at the deployment root

1. In a browser holding no Meridian session, open the client root (`/`). Confirm the marketing surface renders: what Meridian is, who it is for, and the interest form. Confirm you were **not** redirected to the login screen.
2. Confirm the page carries the Meridian name and mark, and shows no organization logo, name, or palette anywhere. Compare with `/apply/{organization-slug}`, which deliberately does wear an organization's identity — the contrast is the point of BRAND-003.
3. Open `/platform`. Confirm the same surface renders at its own address.
4. In the signed-in browser, open `/`. Confirm the home directory loads, exactly as it did before this change.
5. From the signed-in browser, open `/platform`. Confirm the marketing surface renders there rather than being redirected away.

### B. Submitting interest

6. Back in the signed-out browser, fill in the interest form: organization name, your name, your email address, and a couple of sentences about what the organization runs. Submit.
7. Confirm the page replaces the form with a thank-you that names the organization and states plainly that nothing has been created yet — no account, no organization, no sign-up.
8. Reload the page. Confirm the form is back and empty, and that no second inquiry was created.
9. Submit again with a missing name and a malformed email address. Confirm the browser's own validation stops it, and that no inquiry is created.

### C. God Mode review

10. Sign in as Gwen Godmode and open **Organization Inquiries** in the console sidebar, under Operations.
11. Confirm the submission from step 6 is listed, with its organization name, contact, address, status **New**, and submission time.
12. Open it. Confirm the free text is shown in full, that every field is read-only, and that the contact address is labelled as unverified.
13. Confirm there is **no** action anywhere on the screen that creates an organization from the inquiry. Creating one stays a deliberate step on the Organizations screen.
14. Write a review note and press **Mark reviewed**. Confirm the status becomes **Reviewed**, and that your name and the time appear as the last decision.
15. Press **Close**, confirm the inquiry is still listed rather than deleted, then **Reopen** it and confirm it returns to **Reviewed**.
16. Sign in as a console user who holds `platform.organizations` but not `platform.organization-inquiries`. Confirm the sidebar item is absent and that opening the inquiries URL directly is refused.

### D. Rate limits and automated-submission traps

17. From the signed-out browser, submit the form four times with the same contact address. Confirm the fourth is refused with a message about too many submissions, that the form keeps what you typed, and that only three inquiries exist.
18. Clear the cache (`php artisan cache:clear`), then submit repeatedly with a different address each time. Confirm the eleventh is refused and only ten inquiries exist.
19. Clear the cache again. In the browser console, post straight at the endpoint with no form token:

```bash
curl -s -X POST http://127.0.0.1:8000/api/public/organization-inquiries -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"organization_name":"Bot Co","contact_name":"Bot","contact_email":"bot@example.test","description":"spam"}'
```

   Confirm a 422 naming `form_token`, and that no inquiry was created. This is the half of the protection a bot posting blind always fails.
20. Reload the page and submit the form within a second or two of it appearing. Confirm the submission is refused with a message asking you to check it over, that everything you typed is still on screen, and that no inquiry was created.
21. Using developer tools, set a value on the hidden `organization_reference_code` input and submit an otherwise valid form. Confirm the response is the same thank-you a real submission gets, and that **no** inquiry was created.
22. Confirm at no point were you asked to solve a challenge, identify images, or prove you are human.

### E. Audit

23. As Gwen Godmode, read the audit trail for entity type `organization_inquiry`. Confirm:
    - each recorded submission has an `organization_inquiry.submitted` entry naming the organization, contact, address, and status, with no organization scope and no actor;
    - the free-text description is **not** in the audit payload;
    - the trapped submission from step 21 has an `organization_inquiry.discarded` entry with the reason and nothing else;
    - each console decision has an `organization_inquiry.reviewed` entry naming you and carrying the before and after status.

### F. Nodes that do not serve it

24. Lock the node to an event again, restoring the seeded state:

```bash
php artisan tinker --execute='App\Models\Node::query()->where("is_local", true)->update(["event_id" => App\Models\Event::query()->value("id")]);'
```

25. In the signed-out browser, open `/`. Confirm you are sent to the login screen rather than shown the marketing surface.
26. Confirm `GET /api/public/marketing-surface` answers 404 — the surface is not there, not withheld.
27. Set the node role to on-site instead (`MERIDIAN_NODE_ROLE=onsite`, or the node role in the console), clear the event lock, and restart the server. Confirm the same two results.
28. Return the node to its seeded state when you are done.

## Expected results

- The marketing surface renders at the client root for a visitor holding nothing, and carries Meridian identity with no organization branding profile resolved.
- A client holding a session reaches the home directory at the root as before.
- An interest submission creates one inquiry and no organization, user, or staff record.
- The inquiry is reviewable in God Mode by a holder of `platform.organization-inquiries` and by nobody else, and no console path turns one into an organization.
- Both rate limits refuse at their configured counts.
- A submission with no form token, and one that arrives too fast, are both refused with a message and lose nothing the visitor typed. A submission that fills the hidden field gets the ordinary thank-you and creates nothing.
- No challenge is ever presented.
- Submission, discard, and review are all audited.
- An on-site node and an event-locked node send a signed-out visitor to sign in and answer 404 on both public endpoints.

## Evidence to capture

- Screenshot of the marketing surface at the client root in a signed-out browser.
- Screenshot of the same browser's `/apply/{slug}` page, showing the organization identity the marketing surface does not carry.
- Screenshot of the God Mode inquiry list and detail screens.
- The refusal message at the rate limit, and the 422 from the tokenless `curl`.
- Audit trail rows for `organization_inquiry.submitted`, `organization_inquiry.discarded`, and `organization_inquiry.reviewed`.
- The 404 from `GET /api/public/marketing-surface` on the event-locked node and on the on-site node.

## Failure notes

- If an organization logo, name, or palette appears anywhere on the marketing surface, stop: that is BRAND-003 and PUBLIC-001 both.
- If any inquiry row carries an organization, user, or staff reference, stop: PUBLIC-003 is not met.
- If a console action creates an organization, stop: PUBLIC-004 is not met.
- If a legitimate, carefully typed submission is refused and the visitor loses what they wrote, record it — every refusal here is meant to be recoverable, and a refusal that eats a message is the blocked legitimate use PUBLIC-005 rules out.
