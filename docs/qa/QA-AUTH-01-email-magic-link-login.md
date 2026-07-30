# QA-AUTH-01: Email Magic Link Login

## Purpose

Verify that Meridian central authentication supports a verified-email magic-link login path without password login, resolving each login to a global user account, and that it produces the right credential for each caller: a web session for the browser, and a bearer token for a client application that completes verification without leaving the application.

## Requirements covered

- `AUTH-018`, `AUTH-019`, `AUTH-021`, `AUTH-022`, `AUTH-023`, `AUTH-024`, `AUTH-025`, `AUTH-026`, `AUTH-027`, `AUTH-028`, `AUTH-029`
- Technical spec: Section 11.1 Authentication providers
- Technical spec: Section 11.4 API tokens
- Technical spec: Section 13.2 Shared workstation login
- Data/API spec: Section 10.3 Users and Authentication
- Data/API spec: Section 5.4 API authentication
- Data/API spec: Section 12.4 `shared_workstation_login_codes`
- Data/API spec: Section 12.5 API tokens
- UI Implementation Contract: Section 12.1 (`auth.login`, `auth.magic-link-sent`)
- UI Implementation Contract: Section 12.9 (`orchid.api-tokens`)

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

Every issued token is bound to a device, so the exchange carries a device identity. Generate one identifier and reuse it for this whole run — a client generates its identifier once at install and keeps it:

```bash
python -c "import uuid; print(uuid.uuid4())"
```

9. Request a code for an address:

   ```bash
   curl -i -X POST http://127.0.0.1:8000/api/auth/magic-link -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"qa.apilogin@example.com"}'
   ```

10. Confirm the response is `202 Accepted` with `{"status":"sent","expires_in_minutes":15}` and that it says nothing about whether the address is known to Meridian. Repeat with an address that definitely has no account and confirm the response is identical.
11. Read the code out of the mail log — with `MAIL_MAILER=log` the message body is written there. Run `pnpm run server:logs -- --filter "login code"` and copy the eight-character code.
12. Try the exchange with no device first, and confirm it is refused:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/magic-link/verify -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"qa.apilogin@example.com","code":"PASTE-CODE","client_name":"QA laptop"}'
    ```

13. Confirm the response is `422` with `"reason":"device_unresolvable"` and that no token was issued.
14. Exchange the same code — it is still unspent — for a token, this time naming the device:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/magic-link/verify -H "Content-Type: application/json" -H "Accept: application/json" -d '{"email":"qa.apilogin@example.com","code":"PASTE-CODE","client_name":"QA laptop","device":{"id":"PASTE-DEVICE-UUID","label":"QA laptop","platform":"browser","public_key":"'"$(python -c "import base64,os; print(base64.b64encode(os.urandom(32)).decode())")"'"}}'
    ```

15. Confirm the response is `201 Created` and carries `token`, `token_type: Bearer`, an `expires_at` roughly six weeks out, the user's identity, and a `device` block echoing the identifier, label, and platform.
16. Submit the same code a second time and confirm it is refused with `401` and `"reason":"invalid_login_code"`.
17. Revoke the token the client holds, then try to use it again:

    ```bash
    curl -i -X DELETE http://127.0.0.1:8000/api/auth/session -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN"
    curl -i -X DELETE http://127.0.0.1:8000/api/auth/session -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN"
    ```

18. Sign in to the web session in a browser, then send `DELETE /api/auth/session` from that browser with no `Authorization` header and confirm it is refused. A browser session must not authenticate the API.
19. Set `MERIDIAN_API_TOKEN_EXPIRATION_MINUTES=1` in `apps/server/.env`, run `php artisan config:clear`, obtain a fresh token through steps 9–15, wait just over a minute, and confirm the token no longer authenticates. Restore the setting afterwards.

### God Mode token listing and revocation

20. Obtain two fresh tokens through steps 9–15: one on the device identifier used above, and one on a second identifier (a different install of the same client). Keep both token values.
21. Sign in to the console at `http://127.0.0.1:8000/admin` as a God Mode user and open **API Tokens** under Infrastructure.
22. Confirm each token is listed with its client name, the user who holds it, its bound device and platform, its status, and when it was issued, last used, and expires. Confirm no token value appears anywhere on the page.
23. Narrow the list with the **User** filter, then with the **Device** filter, and confirm each shows only the matching tokens.
24. Revoke one token with **Revoke token**, then use that token against the API and confirm it is refused with `401` while the other token still works:

    ```bash
    curl -i -X DELETE http://127.0.0.1:8000/api/auth/session -H "Accept: application/json" -H "Authorization: Bearer PASTE-REVOKED-TOKEN"
    ```

25. Issue two more tokens on the same device identifier, narrow the list to that device, and use **Revoke every token on this device**. Confirm both are refused on their next request.
26. Inspect the audit trail and confirm issuance, revocation, and expiry are recorded without any token value:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\AuditEvent::query()->whereIn('action', ['api_token.issued','api_token.revoked','api_token.expired'])->get(['action','entity_id','actor_device_id','reason'])->each(fn (\$e) => print_r(\$e->toArray()));"
    ```

### Shared workstation login codes

A shared workstation is signed in to with a typed code rather than with a token, and a code can be generated two ways: by God Mode for any known user, and by a user for themselves from a device where they already hold a session. The second is the path that works when the node has no internet — which is the point of it. The kiosk session a code establishes arrives with M16.9; these steps cover generating, listing, and revoking codes.

Create a trusted shared workstation pinned to an event, since a code is scoped to one:

```bash
php apps/server/artisan tinker --execute="\$event = App\Models\Event::query()->firstOrFail(); \$w = App\Models\SharedWorkstation::query()->create(['device_id' => App\Models\Device::query()->create(['device_label' => 'QA kiosk', 'platform' => 'electron', 'first_seen_at' => now()])->id, 'organization_id' => \$event->organization_id, 'event_id' => \$event->id, 'name' => 'qa-kiosk-1', 'trusted' => true, 'context_pinned_at' => now()]); echo \$w->id.PHP_EOL;"
```

27. Sign in to the console as a God Mode user and open **Workstation Login Codes** under Infrastructure.
28. Generate a code for a user on `qa-kiosk-1`. Confirm the code appears once, in a highlighted block naming the user, the workstation, and the expiry roughly six weeks out.
29. Reload the page and confirm the code is gone from it, and that the list shows the code by user, workstation, event, generator, and status — with no code value in any column.
30. Confirm the workstation select offers only trusted workstations. Revoke the workstation's trust in the database (`update shared_workstations set trusted = false`), reload, and confirm it is no longer offered; restore it afterwards.
31. Generate a code for yourself from a device that already holds a session, with no mail and no central node involved. Obtain a bearer token through steps 9–15 first, then:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/shared-workstation-login-code -H "Content-Type: application/json" -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN" -d '{"shared_workstation_id":"PASTE-WORKSTATION-UUID"}'
    ```

32. Confirm the response is `201 Created` and carries `code`, `expires_at`, the calling user, the workstation, and the event resolved from the workstation's pinned context. Confirm nothing was mailed — the mail log has no new message.
33. Try to generate a code for somebody else and confirm it is refused:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/shared-workstation-login-code -H "Content-Type: application/json" -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN" -d '{"shared_workstation_id":"PASTE-WORKSTATION-UUID","user_id":"PASTE-ANOTHER-USER-UUID"}'
    ```

34. Confirm the response is `403` with `"reason":"self_service_scope"` and that no code was created for that user.
35. Set `MERIDIAN_WORKSTATION_LOGIN_CODE_PER_USER_PER_HOUR=2` in `apps/server/.env`, run `php artisan config:clear`, and repeat step 31 three times. Confirm the third is refused with `429`, `"reason":"login_code_rate_limited"`, and a `Retry-After` header. Restore the setting afterwards.
36. In the console, revoke one of the codes and confirm its status becomes Revoked and the revoke action disappears.
37. Inspect the audit trail and confirm generation and revocation are recorded with the authority they were generated under and with no code value:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\AuditEvent::query()->where('action', 'like', 'shared_workstation_login_code.%')->get(['action','entity_id','actor_user_id','actor_device_id','reason'])->each(fn (\$e) => print_r(\$e->toArray()));"
    ```

38. Confirm the stored codes are hashes only:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\SharedWorkstationLoginCode::query()->get(['id','user_id','generated_by_user_id','code_hash','expires_at','used_at','revoked_at'])->each(fn (\$c) => print_r(\$c->toArray()));"
    ```

## Expected results

- `/login` is reachable without authentication.
- Submitting a valid email sends a login message and shows the confirmation screen.
- The login link completes sign-in and redirects to `/home`.
- With `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=true`, a new user record and `auth_identities` row with provider `email` are created for first-time logins.
- Repeat login with the same email reuses the same user account.
- With `MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION=false`, a magic link for an unknown email does not create a user account and returns to `/login` with an error after verification.
- Invalid or expired links do not create a session.
- `POST /api/auth/magic-link` answers `202` identically for a known and an unknown address, so the endpoint reveals nothing about who holds an account.
- `POST /api/auth/magic-link/verify` refuses with `422` and `device_unresolvable` when the request names no device, and that refusal does not spend the login code.
- `POST /api/auth/magic-link/verify` returns a bearer token with an `expires_at` set from `MERIDIAN_API_TOKEN_EXPIRATION_MINUTES`, six weeks out by default, and echoes the device the token is bound to.
- The console lists every issued token with its user and bound device, narrows by either, and shows no token value.
- Revoking one token stops that token on its next request and leaves the same user's other devices signed in; revoking a device's tokens stops all of them.
- Issuance, revocation, and expiry appear in `audit_events` naming the token and device by identifier.
- A login code works once. A second submission is refused with `401` and `invalid_login_code`, as are a wrong code, an expired code, and a code submitted with a different email address.
- `DELETE /api/auth/session` succeeds once, and the same token is refused on its next request.
- A browser session does not authenticate an API route; only a bearer token does.
- With a one-minute lifetime configured, a token that was working stops authenticating once the minute has passed.
- A God Mode operator can generate a shared-workstation login code for any known user, and the code is shown once and never again.
- A signed-in user can generate a code for themselves with no internet, no central node, and no mail, and cannot generate one for anybody else — `403` and `self_service_scope`.
- A generated code is scoped to one user, one event taken from the workstation's pinned context, and one trusted shared workstation, and expires six weeks out.
- Only trusted shared workstations whose pinned event this node holds are offered for generation.
- Generation is rate limited per user and per node, and a refusal names `login_code_rate_limited` with a `Retry-After` header.
- The console lists codes by user, workstation, event, generator, and status, shows no code value in any column, and offers no export or print of codes.
- Generation and revocation appear in `audit_events` as `shared_workstation_login_code.generated` and `shared_workstation_login_code.revoked`, naming the code by identifier and the authority in the reason, with no code value anywhere.
- `shared_workstation_login_codes.code_hash` holds a keyed hash; no column holds a readable code.
- The raw login code and the raw bearer token appear only in the mail body and the HTTP response respectively. Neither appears in `storage/logs/laravel.log` from Meridian's own logging, and neither is stored in readable form — `api_login_codes.code_hash` and `personal_access_tokens.token` hold hashes.

## Evidence to capture

- Screenshot of the login screen.
- Screenshot of the magic-link sent confirmation screen.
- Screenshot of the signed-in `/home` placeholder.
- Excerpt of the mail log showing the login link was generated.
- The `202`, `422`, `201`, and `401` API responses from steps 9–17, with the token value redacted.
- A `select id, email, code_hash, used_at, attempts from api_login_codes` row and a `select id, name, device_id, expires_at, revoked_at from personal_access_tokens` row showing that neither credential is stored in readable form and that every token names a device.
- Screenshot of the God Mode **API Tokens** screen showing tokens by user and device with no token value on the page.
- The audit output from step 26.
- Screenshot of the **Workstation Login Codes** screen immediately after generating a code, showing the code block, and a second screenshot after reload showing it gone.
- The `201`, `403`, and `429` responses from steps 31–35, with the code value redacted.
- The audit output from step 37 and the stored-code output from step 38.

## Failure notes

- If no mail appears, confirm `MAIL_MAILER` and mail transport settings in `.env`.
- If verification fails with `403 Forbidden` or `Invalid signature`, request a fresh link and copy the full URL from the `Meridian magic login link (copy this URL):` log line. Links are signed against the path and query string, not the hostname, but stale or truncated URLs will still fail.
- Disabled-user rejection is covered by automated tests and arrives with later admin workflows.
- If `POST /api/auth/magic-link` returns `429`, the route's rate limit has been reached. Wait a minute; it is five requests per minute per client address by design.
- If the API login code does not appear in the log, confirm `MAIL_MAILER=log`. Meridian never writes the code itself — it is only in the mail body, which is why the log mailer is what makes it readable in development.
- If the exchange is refused with `device_unresolvable` while naming a device, check the identifier is a UUID and that the first request for a new identifier carries `label`, `platform`, and `public_key`. A device the node has never seen is registered from those three fields; without them there is nothing to register.
- If it is refused with `"reason":"device_revoked"`, that device has been revoked. Use a new device identifier; a revoked device is not meant to sign in again.
- Meridian accepts a base64 Ed25519 public key or a PEM RSA public key as `public_key`, the same key formats a device signs node operations with. Anything else is refused rather than stored.
- Tokens issued before device binding existed do not survive the M16.2 migration. On an install upgraded from an earlier build, every client signs in again once.
- Google and Discord login from a client application arrives with M16.3.
- If code generation is refused with `"reason":"workstation_context_unpinned"`, the workstation's `event_id` does not resolve to an event on this node. Pin it to an event that exists here.
- If it is refused with `"reason":"workstation_untrusted"`, the workstation is untrusted, revoked, or its device is revoked. A code that could not be entered is not issued.
- Typing a code into a workstation and the kiosk session it establishes — the 5-minute inactivity timeout, explicit end before switching users, and the lock on Electron restart — arrive with M16.9. Entry is exercised by automated tests until then.
