# QA-AUTH-01: Email Magic Link Login

## Purpose

Verify that Meridian central authentication supports a verified-email magic-link login path without password login, resolving each login to a global user account, and that it produces the right credential for each caller: a web session for the browser, and a bearer token for a client application that completes verification without leaving the application.

## Requirements covered

- `AUTH-018`, `AUTH-019`, `AUTH-021`, `AUTH-022`, `AUTH-023`, `AUTH-024`, `AUTH-025`, `AUTH-026`, `AUTH-027`, `AUTH-028`, `AUTH-029`, `AUTH-030`
- Technical spec: Section 11.1 Authentication providers
- Technical spec: Section 11.4 API tokens
- Technical spec: Section 13.2 Shared workstation login
- Technical spec: Section 13.3 Shared workstation session behavior
- Data/API spec: Section 10.3 Users and Authentication
- Data/API spec: Section 5.4 API authentication
- Data/API spec: Section 12.4 `shared_workstation_login_codes`
- Data/API spec: Section 12.5 API tokens
- Data/API spec: Section 12.6 `shared_workstation_sessions`
- UI Implementation Contract: Section 12.1 (`auth.login`, `auth.magic-link-sent`, `auth.code-entry`)
- UI Implementation Contract: Section 12.8 (`kiosk.home`, `kiosk.workstation-login`, `kiosk.safe-timeout`)
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
11. Read the code out of the mail log — with `MAIL_MAILER=log` the message body is written there, and the code sits on its own line below "Enter this code in the Meridian app you are signing in to:". Print the most recent one with `grep -A 2 "Enter this code" apps/server/storage/logs/laravel.log | tail -1`, or run `pnpm run server:logs` and watch it arrive. A `--filter` on "login code" matches the subject line rather than the code.
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

A shared workstation is signed in to with a typed code rather than with a token, and a code can be generated two ways: by God Mode for any known user, and by a user for themselves from a device where they already hold a session. The second is the path that works when the node has no internet — which is the point of it. These steps cover generating, listing, and revoking codes; the session a code establishes is the section after this one.

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

### Shared workstation sessions

What entering a code establishes. The rules being checked here are the ones a shared machine in a public place depends on: it forgets whoever was signed in when nobody is watching, it cannot be handed over quietly, and it does not throw away work somebody already did.

Generate a fresh code for a user on `qa-kiosk-1` through step 28 or 31, then enter it:

```bash
curl -i -X POST http://127.0.0.1:8000/api/auth/shared-workstation-session -H "Content-Type: application/json" -H "Accept: application/json" -d '{"shared_workstation_id":"PASTE-WORKSTATION-UUID","code":"PASTE-CODE"}'
```

39. Confirm the response is `201 Created` and carries `session_key`, the session's `started_at`, `last_activity_at`, `expires_at`, and `inactivity_timeout_seconds` of `300`, the active user's name, the workstation with its pinned organization and department, and `event_id`. Confirm it carries no `token`, `access_token`, or `plainTextToken`.
40. Confirm no token was issued and no device was trusted by the entry:

    ```bash
    php apps/server/artisan tinker --execute="echo App\Models\ApiToken::query()->count().' tokens '.App\Models\DeviceTrust::query()->count().' trusts'.PHP_EOL;"
    ```

41. Confirm the session key authenticates `/api/me` in its own header, and is refused as a bearer token:

    ```bash
    curl -s http://127.0.0.1:8000/api/me -H "Accept: application/json" -H "X-Meridian-Workstation-Session: PASTE-SESSION-KEY"
    curl -i http://127.0.0.1:8000/api/me -H "Accept: application/json" -H "Authorization: Bearer PASTE-SESSION-KEY"
    ```

42. Confirm the first answers `200` with the active user's roles and capabilities, and the second answers `401`.
43. Read the session and confirm reading it slides the deadline — this is the "I'm still here" action behind the timeout warning:

    ```bash
    curl -s http://127.0.0.1:8000/api/auth/shared-workstation-session -H "Accept: application/json" -H "X-Meridian-Workstation-Session: PASTE-SESSION-KEY"
    ```

44. Age the session past its window and confirm it stops authenticating, and that the timeout is stamped at the moment it expired rather than now:

    ```bash
    php apps/server/artisan tinker --execute="\$s = App\Models\SharedWorkstationSession::query()->latest('started_at')->firstOrFail(); \$s->forceFill(['last_activity_at' => now()->subMinutes(30)])->save(); echo 'aged'.PHP_EOL;"
    curl -i http://127.0.0.1:8000/api/me -H "Accept: application/json" -H "X-Meridian-Workstation-Session: PASTE-SESSION-KEY"
    php apps/server/artisan tinker --execute="\$s = App\Models\SharedWorkstationSession::query()->latest('started_at')->firstOrFail(); echo \$s->ended_reason.' at '.\$s->ended_at.' (last activity '.\$s->last_activity_at.')'.PHP_EOL;"
    ```

45. Confirm the request answered `401`, `ended_reason` is `timed_out`, and `ended_at` is five minutes after `last_activity_at` — around twenty-five minutes ago, not the time the check ran. A workstation nobody touched for half an hour was signed out five minutes in.
46. Establish a session with a fresh code, then establish another at the same workstation with a second fresh code. Confirm the first is closed as `superseded` and the second is live:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\SharedWorkstationSession::query()->latest('started_at')->take(2)->get(['id','user_id','ended_at','ended_reason'])->each(fn (\$s) => print_r(\$s->toArray()));"
    ```

47. End the live session and confirm the key stops working:

    ```bash
    curl -i -X DELETE http://127.0.0.1:8000/api/auth/shared-workstation-session -H "Accept: application/json" -H "X-Meridian-Workstation-Session: PASTE-SESSION-KEY"
    curl -i http://127.0.0.1:8000/api/me -H "Accept: application/json" -H "X-Meridian-Workstation-Session: PASTE-SESSION-KEY"
    ```

48. Confirm the session ends are audited with their reasons and no session key anywhere:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\AuditEvent::query()->where('action', 'shared_workstation_session.ended')->get(['action','entity_id','actor_user_id','actor_device_id','event_id','reason'])->each(fn (\$e) => print_r(\$e->toArray()));"
    ```

49. Confirm no readable session key is stored:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\SharedWorkstationSession::query()->get(['id','user_id','event_id','session_key_hash','started_at','last_activity_at','ended_at','ended_reason'])->each(fn (\$s) => print_r(\$s->toArray()));"
    ```

Now the Kiosk surfaces. Start the client in Kiosk mode with `pnpm run client:dev:kiosk`, and point it at the node and at this workstation from the browser console before loading a Kiosk route:

```js
localStorage.setItem("meridian.node.url", "http://127.0.0.1:8000");
localStorage.setItem("meridian.workstation.id", "PASTE-WORKSTATION-UUID");
```

50. Open `/kiosk` and confirm it redirects to `/kiosk/sign-in`.
51. Enter a fresh code and confirm the kiosk lands on `/kiosk` with the active user's name shown in the session bar above the surface.
52. Type `/kiosk/sign-in` into the address bar while signed in and confirm it redirects back to `/kiosk` — a user must end their session before another can sign in.
53. Leave the workstation untouched for four minutes and confirm a warning appears in the session bar with a control to continue. Use it, and confirm the warning clears and the countdown restarts without asking for the code again.
54. Leave it untouched for the full five minutes and confirm the kiosk lands on `/kiosk/timed-out`, which shows no name, event, or record.
55. Sign in again, queue a Field Report offline (see QA-FR-01), then end the session with the session bar's control. Confirm the kiosk lands on `/kiosk/sign-in` and that the queued report is still queued afterwards — sign in again and confirm it is still listed as pending.
56. With a session live, restart the client (reload the page, or restart the Electron wrapper). Confirm it comes up locked at `/kiosk/sign-in` rather than restoring the previous user, and that `localStorage` holds no session key and no cached session document.

### Signing in from the client application

The steps above stand in for a client with `curl`. These are the same exchange made by the application itself, from the sign-in screens, and they are also the regression cover for the shared token that used to authenticate this API (M16.11).

Seed the development fixture first — it is seed data now, not a credential, and the account is reached by signing in as it:

```bash
php apps/server/artisan meridian:seed-local-field-fixture
```

Start the client in Field mode with `pnpm run client:dev:field -- --host 127.0.0.1` and point it at the node from the browser console if it is not already: `localStorage.setItem("meridian.node.url", "http://127.0.0.1:8000")`.

57. Open `http://127.0.0.1:5173/login`, enter `local-field@meridian.test`, and submit. Confirm the application moves to the code entry screen, names the address the code went to, and states how long the code lasts.
58. Read the code out of the mail log (`grep -A 2 "Enter this code" apps/server/storage/logs/laravel.log | tail -1`), enter it, and confirm the application lands on Home with the fixture user's name in the shell's user menu.
59. In the browser console, confirm `localStorage` holds `meridian.api-token.v1` and `meridian.device.id`, and that the stored entry carries the token and no password.
60. In the God Mode console's **API Tokens** screen, confirm a token is listed for that user, bound to a device labeled for the client application and platform that signed in.
61. Submit a Field Report from the client (see QA-FR-01) and confirm the node accepts it — the upload path now authenticates with this token and nothing else.
62. Open the user menu and choose **Sign out**. Confirm the application returns to `/login`, that `meridian.api-token.v1` is gone from `localStorage`, and that the token is refused if it is replayed:

    ```bash
    curl -i http://127.0.0.1:8000/api/me -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN"
    ```

63. With the client still signed in and open, revoke that device's token from the God Mode **API Tokens** screen, then switch back to the client window without reloading it. Confirm it signs itself out within a moment: the shell reports nobody signed in, `meridian.api-token.v1` and `meridian.session.v1` are gone from `localStorage`, and the application is on `/login`.
64. Confirm the removed shared token authenticates nothing, with or without the settings a node used to carry:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/commands/submit-field-report -H "Content-Type: application/json" -H "Accept: application/json" -H "Authorization: Bearer local-field-dev-token" -d '{"id":"11111111-1111-4111-8111-111111111111","event_id":"11111111-1111-4111-8111-111111111111","title":"Shared token","body":"Should be refused."}'
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
- Entering a valid code answers `201` with a session key, the active user, the workstation, the event, and a five-minute inactivity deadline — and issues no API token, trusts no device, and returns nothing token-shaped.
- The session key authenticates `/api/me` in `X-Meridian-Workstation-Session` and is refused in `Authorization: Bearer`. A shared-workstation session is never a personal token.
- Reading the session slides its deadline, so continuing from the timeout warning requires nothing to be re-entered.
- A session past its window stops authenticating, and its end is stamped at the moment it expired rather than the moment it was noticed.
- A second entry at the same workstation closes the first as `superseded`. A workstation holds one session at a time.
- Ending a session stops its key on the next request, and is answered as ended whether or not there was one to end.
- Session ends appear in `audit_events` as `shared_workstation_session.ended` with `signed_out`, `timed_out`, or `superseded` as the reason and no session key anywhere.
- `shared_workstation_sessions.session_key_hash` holds a keyed hash; no column holds a readable session key.
- The Kiosk shows the active user's name in the session bar at all times while signed in, and nothing while locked.
- `/kiosk/sign-in` is unreachable while a session is live. Switching users requires ending the session first, including by typing the URL.
- The Kiosk warns before the timeout and offers a control that continues the session.
- A timeout lands on `/kiosk/timed-out`, which holds no name, event, or record. An explicit end lands on `/kiosk/sign-in`.
- Ending a session leaves queued Field Reports queued; they are still pending after signing in again.
- Restarting the client comes up locked, with no session key and no cached session document in `localStorage`.
- A person signs in to the client application without leaving it: an address, a code, and a session — no browser redirect and no password.
- The device the client registers appears in the God Mode token list, so a token issued to an application is revocable as a unit of hardware like any other.
- Field Report upload works under that token, and only under it.
- Signing out returns the application to `/login`, removes the stored token from the device, and stops that token working on the node.
- A token revoked in God Mode signs its device out when somebody next returns to the application, with no reload: the credential and the cached session are dropped and the client is on the sign-in screen.
- A client working from a cached session offline is not sent to sign in — it is an offline device mid-event, and the cache exists so it keeps working (CLIENT-007).
- The removed `local.field` shared token authenticates nothing. `Bearer local-field-dev-token` is refused like any other string that is not an issued token, whether or not the settings it used are present.
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
- The `201` from step 39 with the session key redacted, and the token/trust counts from step 40.
- The paired `/api/me` responses from step 41 showing the session key accepted in its own header and refused as a bearer token.
- The aged-session output from step 44 showing `timed_out` stamped five minutes after the last activity.
- The audit output from step 48 and the stored-session output from step 49.
- Screenshots of the Kiosk session bar signed in, showing the timeout warning, and of `/kiosk/timed-out` holding no name or event.
- Screenshot of the pending Field Report still queued after the session ended and a new one began.
- Screenshots of the client's sign-in and code entry screens, and of the shell user menu showing the signed-in name.
- The `401` from step 62 after signing out, the signed-out client after revocation in step 63, and the `401` from step 64 for the removed shared token.

## Failure notes

- If no mail appears, confirm `MAIL_MAILER` and mail transport settings in `.env`.
- If verification fails with `403 Forbidden` or `Invalid signature`, request a fresh link and copy the full URL from the `Meridian magic login link (copy this URL):` log line. Links are signed against the path and query string, not the hostname, but stale or truncated URLs will still fail.
- Disabled-user rejection is covered by automated tests and arrives with later admin workflows.
- If `POST /api/auth/magic-link` returns `429`, the route's rate limit has been reached. Wait a minute; it is five requests per minute per client address by design.
- If the API login code does not appear in the log, confirm `MAIL_MAILER=log`. Meridian never writes the code itself — it is only in the mail body, which is why the log mailer is what makes it readable in development.
- If the Kiosk sign-in screen says the machine is not set up as a trusted shared workstation, `meridian.workstation.id` is unset or holds a workstation this node does not have. The screen offers no code field in that state on purpose: it is a setup problem, and a field the node could only refuse would teach the wrong lesson.
- If a Kiosk sign-in is refused with `login_code_rate_limited`, the per-workstation entry limit has been reached by repeated QA attempts. A successful sign-in clears the counter; otherwise wait out the window.
- If the Kiosk's countdown disagrees with when the node actually signs the session out, the two clocks disagree. The deadline in the response is the node's, and the Kiosk counts down against it, so a workstation whose clock is off shows the wrong number of seconds while still being signed out at the right moment.
- If the exchange is refused with `device_unresolvable` while naming a device, check the identifier is a UUID and that the first request for a new identifier carries `label`, `platform`, and `public_key`. A device the node has never seen is registered from those three fields; without them there is nothing to register.
- If it is refused with `"reason":"device_revoked"`, that device has been revoked. Use a new device identifier; a revoked device is not meant to sign in again.
- Meridian accepts a base64 Ed25519 public key or a PEM RSA public key as `public_key`, the same key formats a device signs node operations with. Anything else is refused rather than stored.
- Tokens issued before device binding existed do not survive the M16.2 migration. On an install upgraded from an earlier build, every client signs in again once.
- Google and Discord login from a client application arrives with M16.3.
- If code generation is refused with `"reason":"workstation_context_unpinned"`, the workstation's `event_id` does not resolve to an event on this node. Pin it to an event that exists here.
- If it is refused with `"reason":"workstation_untrusted"`, the workstation is untrusted, revoked, or its device is revoked. A code that could not be entered is not issued.
- Typing a code into a workstation and the kiosk session it establishes — the 5-minute inactivity timeout, explicit end before switching users, and the lock on Electron restart — arrive with M16.9. Entry is exercised by automated tests until then.
