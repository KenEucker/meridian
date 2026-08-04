# QA-APPLY-02 Applicant Portal

## Purpose

Verify that somebody who has applied — and who holds no Meridian account, no staff record, and no session — can reach their own applications from the email address they applied with, read what has become of each one, and withdraw one that is still withdrawable; and verify that doing so tells nobody anything they should not learn. This script covers requesting a signed link from both public application surfaces, the link's expiry and tamper resistance, the bounded portal session, the identical response for known and unknown addresses, the absence of a Do Not Staff auto-rejected application, applicant-only withdrawal, the two rate limits, and the audit trail for issuance and withdrawal.

Review-side application handling stays in `QA-APPLY-01`. This script is the applicant's own half of it.

## Requirements covered

- `APP-004`
- `APP-012`
- `APP-013`
- `APP-014`
- `APP-015`
- `AUTH-010`
- `STAT-006` (auto-rejection stays invisible to the applicant)
- Requirements section 7.4
- Data/API spec section 10.5 (applicant portal)
- UI implementation contract section 12.1 (`public.apply`)
- Meridian Alpha 1 task M18.22

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and development scenario seeded (`php artisan migrate:fresh --seed`).
- Development web server running.
- `MAIL_MAILER=log` (the default in development). The portal link is written to `storage/logs/laravel.log`, which is where you read it from.
- Client dev server running if you are checking the Vue participation surface as well as the server-rendered form.

## Personas

- **Applicant with applications**: any address that has submitted at least one application. Submit one from `/{organization-slug}/{event-slug}/apply` if the seeded scenario does not already have one you know the address of.
- **Applicant with no applications**: an address that has never applied, for example `nobody@example.test`.
- **Do Not Staff applicant**: the seeded Debbie DNS persona, or any address on an organization Do Not Staff record. Submit an application with it if none exists; it will be auto-rejected.
- **Organizer or God Mode user**: to read the audit trail.

## Setup data

- One event accepting applications, with a known organization slug and event slug.
- One address with a Submitted application (withdrawable) and, ideally, one with an Approved or Rejected application (not withdrawable), so both presentations appear on one page.
- One address holding an auto-rejected Do Not Staff application and nothing else.
- Note the configured values if they have been changed from their defaults: `MERIDIAN_APPLICANT_PORTAL_LINK_EXPIRES_MINUTES` (60), `MERIDIAN_APPLICANT_PORTAL_SESSION_MINUTES` (60), `MERIDIAN_APPLICANT_PORTAL_REQUESTS_PER_EMAIL_PER_HOUR` (5), `MERIDIAN_APPLICANT_PORTAL_REQUESTS_PER_CLIENT_PER_HOUR` (20).

## Steps

### A. Requesting a link

1. Open `/{organization-slug}/{event-slug}/apply` as a signed-out visitor. Confirm the page offers **Find your applications**, and follow it.
2. Confirm `/applications/link` states that no account is needed and that the link signs you in to nothing.
3. Enter the address of the applicant with applications and submit.
4. Confirm the confirmation page names the address and says a link is on its way *if that address has any applications*.
5. Read `storage/logs/laravel.log` and confirm a portal link was written, and that the mail body says the link expires and signs you in to nothing.
6. Repeat steps 2–4 with the address that has never applied. Confirm the confirmation page is word for word the same, apart from the address itself.
7. Confirm **no** portal link was written to the log for that address.
8. Repeat with the Do Not Staff address. Confirm the confirmation page is again the same and that no link was written.

### B. Opening the portal

9. Follow the link issued in step 5. Confirm the browser lands on `/applications` with no signature or address in the URL bar.
10. Confirm the page lists every application made with that address, each showing what was applied to (an event by name, or joining the organization by name), the submission date and time, and the current status.
11. Confirm a Submitted application offers **Withdraw this application**, and that a decided application (Approved, Rejected, Deferred, Withdrawn) offers no control at all rather than a disabled one.
12. Confirm no other applicant's application is listed.
13. Edit the address in the signed link you followed in step 9 to a different applicant's address and follow it. Confirm the node refuses it (403) rather than opening that person's applications.
14. Follow the same link again unmodified. Confirm it still opens the portal until it expires, and that it stops working once `MERIDIAN_APPLICANT_PORTAL_LINK_EXPIRES_MINUTES` has passed.

### C. Do Not Staff invisibility

15. Request a link for an address that holds both a Do Not Staff auto-rejected application and an ordinary one — create the pair if the seeded data has none — and open the portal.
16. Confirm the ordinary application is listed and the auto-rejected one is **absent**: not shown, not counted, and not described as hidden.

### D. Withdrawal

17. In the portal, withdraw the Submitted application.
18. Confirm the page reports the withdrawal and that the application's status is now Withdrawn with no withdraw control.
19. Confirm the same application appears as Withdrawn in the organizer's application list and in the God Mode application list, with the decision reason naming the applicant portal.
20. As an organizer, confirm you cannot withdraw an application on the applicant's behalf from any review surface (APP-004).

### E. Session bounds

21. Press **Close your applications**. Confirm you are returned to the request form and that navigating back to `/applications` no longer shows anything.
22. Open the portal again with a fresh link, wait out `MERIDIAN_APPLICANT_PORTAL_SESSION_MINUTES` (or lower the value and restart the server for a quicker check), reload `/applications`, and confirm it asks for a new link rather than rendering the applications.
23. In a browser that has never followed a link, navigate directly to `/applications`. Confirm it asks for a link.

### F. Rate limits

24. Request a link for the same address `MERIDIAN_APPLICANT_PORTAL_REQUESTS_PER_EMAIL_PER_HOUR` times. Confirm each is accepted.
25. Request once more. Confirm the refusal names too many requests and says nothing about whether the address has applications.
26. Confirm a request for a *different* address from the same browser is still accepted until the per-client limit is reached.

### G. The client participation surface

27. Open `/apply/{organization-slug}` on the client dev server. Confirm the **Applied already?** panel is present.
28. Enter an address and submit. Confirm the confirmation wording matches the server-rendered surface and that the link arrives in the log for an address with applications and does not for one without.
29. Confirm the panel is also present on a closed event's page and after submitting an application, since those are the pages somebody chasing an old application arrives at.

### H. Audit

30. As an organizer or God Mode user, open the audit trail and confirm an `applicant_portal.link_issued` entry exists for each issued link, recording the address and the number of applications covered, with no actor user.
31. Confirm an `event_application.withdrawn` entry exists for the withdrawal from step 17, with before and after status values and a reason naming the applicant portal.
32. Confirm no audit entry exists naming the Do Not Staff address from step 8.

## Expected results

- The request response, the confirmation page, and the API response are identical for an address with applications, an address with none, and an address whose only application was auto-rejected.
- A link is mailed only to an address that has applications the portal may show.
- The link is signed and time limited; editing the address in it is refused, and following it after expiry is refused.
- The portal shows only the applications made with the verified address, each with scope, submission date, and current status.
- An auto-rejected Do Not Staff application never appears, and its address behaves exactly like one that never applied.
- Only a Submitted application offers withdrawal, only through the portal or the submitting browser's own session, and never to an organizer acting on the applicant's behalf.
- The portal session ends on request and on its own, and `/applications` is not reachable without following a link.
- Both rate limits refuse further requests without disclosing anything about the address.
- Link issuance and applicant withdrawal are both audited.

## Evidence to capture

- Screenshots of the request form, the confirmation page for a known and an unknown address side by side, the portal listing, and the portal after a withdrawal.
- The mail log entry for an issued link, with the URL redacted below the signature.
- A screenshot or query output showing the withdrawn application's status and decision reason.
- Audit rows for `applicant_portal.link_issued` and `event_application.withdrawn`.
- The refusal shown when a rate limit is reached.

## Failure notes

- Record any difference at all between the known-address and unknown-address responses, including timing, wording, and status code. A difference here is the enumeration APP-014 forbids, not a cosmetic inconsistency.
- Record any appearance of a Do Not Staff auto-rejected application, including a label, a count, an empty row, or a difference in behaviour for that address.
- Record any case where a link opens applications belonging to a different address.
- Record any case where the portal remains usable after being closed or after its session expired.
- Record any withdrawal accepted for an application that was not Submitted, or by anybody other than the applicant.
