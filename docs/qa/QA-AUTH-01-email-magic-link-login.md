# QA-AUTH-01: Email Magic Link Login

## Purpose

Verify that Meridian central authentication supports a verified-email magic-link login path without password login, resolving each login to a global user account, and that it produces the right credential for each caller: a web session for the browser, and a bearer token for a client application that completes verification without leaving the application.

## Requirements covered

- `AUTH-018`, `AUTH-019`, `AUTH-024`
- Technical spec: Section 11.1 Authentication providers
- Technical spec: Section 11.4 API tokens
- Data/API spec: Section 10.3 Users and Authentication
- Data/API spec: Section 5.4 API authentication
- UI Implementation Contract: Section 12.1 (`auth.login`, `auth.magic-link-sent`)

## Environment

- Local development environment.
- PHP 8.5.x and Composer available.
- SQLite or PostgreSQL database configured for `apps/server`.
- Mail driver configured (`log` or `array` is sufficient for development).

## Personas

- Staff/applicant without an existing account
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

### API login (client applications)

A client application cannot use the browser link, so it asks for a code and exchanges it for a bearer token. These steps stand in for what the web, mobile Field, and desktop applications will do.

9. Request a code for an address:

   ```bash
   curl -i -X POST http://127.0.0.1:8000/api/auth/magic-link -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"qa.apilogin@example.com"}'
   ```

10. Confirm the response is `202 Accepted` with `{"status":"sent","expires_in_minutes":15}` and that it says nothing about whether the address is known to Meridian. Repeat with an address that definitely has no account and confirm the response is identical.
11. Read the code out of the mail log — with `MAIL_MAILER=log` the message body is written there. Run `pnpm run server:logs -- --filter "login code"` and copy the eight-character code.
12. Exchange it for a token:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/magic-link/verify -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"qa.apilogin@example.com","code":"PASTE-CODE","client_name":"QA laptop"}'
    ```

13. Confirm the response is `201 Created` and carries `token`, `token_type: Bearer`, an `expires_at` roughly six weeks out, and the user's identity.
14. Submit the same code a second time and confirm it is refused with `401` and `"reason":"invalid_login_code"`.
15. Revoke the token the client holds, then try to use it again:

    ```bash
    curl -i -X DELETE http://127.0.0.1:8000/api/auth/session -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN"
    curl -i -X DELETE http://127.0.0.1:8000/api/auth/session -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN"
    ```

16. Sign in to the web session in a browser, then send `DELETE /api/auth/session` from that browser with no `Authorization` header and confirm it is refused. A browser session must not authenticate the API.
17. Set `MERIDIAN_API_TOKEN_EXPIRATION_MINUTES=1` in `apps/server/.env`, run `php artisan config:clear`, obtain a fresh token through steps 9–12, wait just over a minute, and confirm the token no longer authenticates. Restore the setting afterwards.

## Expected results

- `/login` is reachable without authentication.
- Submitting a valid email sends a login message and shows the confirmation screen.
- The login link completes sign-in and redirects to `/home`.
- With `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=true`, a new user record and `auth_identities` row with provider `email` are created for first-time logins.
- Repeat login with the same email reuses the same user account.
- With `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=false`, a magic link for an unknown email does not create a user account and returns to `/login` with an error after verification.
- Invalid or expired links do not create a session.
- `POST /api/auth/magic-link` answers `202` identically for a known and an unknown address, so the endpoint reveals nothing about who holds an account.
- `POST /api/auth/magic-link/verify` returns a bearer token with an `expires_at` set from `MERIDIAN_API_TOKEN_EXPIRATION_MINUTES`, six weeks out by default.
- A login code works once. A second submission is refused with `401` and `invalid_login_code`, as are a wrong code, an expired code, and a code submitted with a different email address.
- `DELETE /api/auth/session` succeeds once, and the same token is refused on its next request.
- A browser session does not authenticate an API route; only a bearer token does.
- With a one-minute lifetime configured, a token that was working stops authenticating once the minute has passed.
- The raw login code and the raw bearer token appear only in the mail body and the HTTP response respectively. Neither appears in `storage/logs/laravel.log` from Meridian's own logging, and neither is stored in readable form — `api_login_codes.code_hash` and `personal_access_tokens.token` hold hashes.

## Evidence to capture

- Screenshot of the login screen.
- Screenshot of the magic-link sent confirmation screen.
- Screenshot of the signed-in `/home` placeholder.
- Excerpt of the mail log showing the login link was generated.
- The `202`, `201`, and `401` API responses from steps 9–15, with the token value redacted.
- A `select id, email, code_hash, used_at, attempts from api_login_codes` row and a `select id, name, expires_at from personal_access_tokens` row showing that neither credential is stored in readable form.

## Failure notes

- If no mail appears, confirm `MAIL_MAILER` and mail transport settings in `.env`.
- If verification fails with `403 Forbidden` or `Invalid signature`, request a fresh link and copy the full URL from the `Meridian magic login link (copy this URL):` log line. Links are signed against the path and query string, not the hostname, but stale or truncated URLs will still fail.
- Disabled-user rejection is covered by automated tests and arrives with later admin workflows.
- If `POST /api/auth/magic-link` returns `429`, the route's rate limit has been reached. Wait a minute; it is five requests per minute per client address by design.
- If the API login code does not appear in the log, confirm `MAIL_MAILER=log`. Meridian never writes the code itself — it is only in the mail body, which is why the log mailer is what makes it readable in development.
- Binding a token to a device and refusing issuance without a resolvable device (`AUTH-021`), God Mode token listing and revocation (`AUTH-022`), and issuance/expiry/revocation audit (`AUTH-025`) arrive with M16.2 and are not part of this script yet. Google and Discord login from a client application arrives with M16.3.
