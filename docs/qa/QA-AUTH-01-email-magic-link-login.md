# QA-AUTH-01: Email Magic Link Login

## Purpose

Verify that Meridian central authentication supports a verified-email magic-link login path without password login, resolving each login to a global user account and establishing a web session.

## Requirements covered

- Technical spec: Section 11.1 Authentication providers
- Data/API spec: Section 10.3 Users and Authentication
- UI Implementation Contract: Section 12.1 (`auth.login`, `auth.magic-link-sent`)

## Environment

- Local development environment.
- PHP 8.5.x and Composer available.
- SQLite or PostgreSQL database configured for `apps/server`.
- Mail driver configured (`log` or `array` is sufficient for development).

## Personas

- Volunteer/applicant without an existing account
- Returning user with an existing Meridian account
- Human reviewer

## Setup data

- No seed data required.
- For mail inspection in development, set `MAIL_MAILER=log` in `apps/server/.env`.
- Leave `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=true` for Alpha 1 testing when verifying first-time account creation.
- Set `APP_URL=http://127.0.0.1:8000` in `apps/server/.env` so signed login links match the URL used by `pnpm run server:dev`.

## Steps

1. From `apps/server`, start the development server: `php artisan serve`.
2. Open `http://127.0.0.1:8000/login` and confirm the magic-link login screen loads.
3. Enter a new email address (for example `qa.magiclink@example.com`) and submit the form.
4. Confirm the browser redirects to the magic-link sent confirmation screen.
5. In a second terminal, run `pnpm run server:logs -- --filter "Meridian magic login link"` and request a fresh login link. Copy the `url` value from the log line (or use the link from the mail log output).
6. Open the login link in the same browser session.
7. Confirm the browser redirects to `/home` and shows the signed-in placeholder page with the submitted email.
8. Sign out or use a private window, then repeat steps 2–7 with the same email and confirm the existing account is reused (no duplicate user is created in admin/database inspection if available).

## Expected results

- `/login` is reachable without authentication.
- Submitting a valid email sends a login message and shows the confirmation screen.
- The login link completes sign-in and redirects to `/home`.
- With `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=true`, a new user record and `auth_identities` row with provider `email` are created for first-time logins.
- Repeat login with the same email reuses the same user account.
- With `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=false`, a magic link for an unknown email does not create a user account and returns to `/login` with an error after verification.
- Invalid or expired links do not create a session.

## Evidence to capture

- Screenshot of the login screen.
- Screenshot of the magic-link sent confirmation screen.
- Screenshot of the signed-in `/home` placeholder.
- Excerpt of the mail log showing the login link was generated.

## Failure notes

- If no mail appears, confirm `MAIL_MAILER` and mail transport settings in `.env`.
- If verification fails with `403 Forbidden` or `Invalid signature`, request a fresh link and copy the full URL from the `Meridian magic login link (copy this URL):` log line. Links are signed against the path and query string, not the hostname, but stale or truncated URLs will still fail.
- Disabled-user rejection is covered by automated tests and arrives with later admin workflows.
