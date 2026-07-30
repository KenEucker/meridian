# QA-AUTH-02: Google OAuth Login

## Purpose

Verify that Meridian central authentication supports Google OAuth without password login, requires a verified Google email, resolves each login to a global user account by email, and produces the right credential for each caller: a web session for a browser signing in at `/login`, and a bearer token returned to a client application that signed in through the system browser.

## Requirements covered

- `AUTH-020`
- Technical spec: Section 11.1 Authentication providers
- Technical spec: Section 11.4 API tokens
- Data/API spec: Section 10.3 Users and Authentication
- Data/API spec: Section 5.4 API authentication
- UI Implementation Contract: Section 12.1 (`auth.login`, `auth.provider-callback`)
- UI Implementation Contract: Section 18.2 Authentication and Re-authentication

## Environment

- Local development environment.
- PHP 8.5.x and Composer available.
- SQLite or PostgreSQL database configured for `apps/server`.
- A Google OAuth test client configured for a local redirect URL.

## Personas

- Staff/applicant using a Google account with a verified email
- Returning user with an existing Meridian account for the same verified email
- Staff member signing in to the web, mobile Field, or desktop application with Google
- Human reviewer

## Setup data

- No seed data required.
- Set `APP_URL=http://127.0.0.1:8000` in `apps/server/.env`.
- Set `GOOGLE_OAUTH_CLIENT_ID` to the Google OAuth test client ID.
- Set `GOOGLE_OAUTH_CLIENT_SECRET` to the Google OAuth test client secret.
- Set `GOOGLE_OAUTH_REDIRECT_URI=http://127.0.0.1:8000/login/google/callback`.
- In the Google OAuth client, allow the same redirect URI. The provider handoff uses the same one, so no second redirect URI is registered.
- Leave the handoff return targets at their defaults: `MERIDIAN_API_HANDOFF_RETURN_WEB=http://127.0.0.1:8000/login/handoff`, `MERIDIAN_API_HANDOFF_RETURN_MOBILE=org.meridian.field://auth/handoff`, `MERIDIAN_API_HANDOFF_RETURN_DESKTOP=org.meridian.kiosk://auth/handoff`.

## Steps

1. From `apps/server`, start the development server: `php artisan serve`.
2. Open `http://127.0.0.1:8000/login` and confirm the login screen shows `Continue with Google`.
3. Select `Continue with Google`.
4. Confirm the browser leaves Meridian for Google account selection.
5. Choose a Google test account with a verified email and approve the requested profile/email scopes.
6. Confirm Google redirects back to Meridian.
7. Confirm the browser redirects to `/home` and shows the signed-in placeholder page with the Google account email.
8. Sign out or use a private window, then repeat steps 2-7 with the same Google account and confirm the existing account is reused.

### Provider handoff to a client application

A client application cannot follow the Google redirect itself, so it hands the sign-in to the system browser and waits for the result. These steps stand in for what the web, mobile Field, and desktop applications do; the browser you open in step 13 is playing the part of the system browser.

Each sign-in needs a fresh PKCE verifier and its challenge, which a client generates and keeps:

```bash
python -c "import base64,hashlib,os; v=base64.urlsafe_b64encode(os.urandom(48)).rstrip(b'=').decode(); print('verifier ', v); print('challenge', base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b'=').decode())"
```

Every issued token is bound to a device, so the exchange carries a device identity. Generate one identifier per client target and reuse it, as an install would:

```bash
python -c "import uuid; print(uuid.uuid4())"
```

9. Confirm which return addresses this node offers:

   ```bash
   php apps/server/artisan tinker --execute="print_r(config('meridian.api_tokens.provider_handoff.return_targets'));"
   ```

10. Start a handoff as the web client. Note that the client makes this request itself — no browser is involved yet:

    ```bash
    curl -s -H "Accept: application/json" "http://127.0.0.1:8000/api/auth/google/start?client=web&code_challenge=PASTE-CHALLENGE&code_challenge_method=S256"
    ```

11. Confirm the response is `201 Created` and carries an `authorization_url` at `accounts.google.com`, `provider: google`, `client_target: web`, a `return_url` matching `MERIDIAN_API_HANDOFF_RETURN_WEB`, a `state`, and an `expires_at` about ten minutes out.
12. Confirm the `state` in the response is not stored as-is:

    ```bash
    php apps/server/artisan tinker --execute="print_r(App\Models\ApiAuthHandoff::query()->latest()->first(['provider','client_target','redirect_uri','state_hash','status','expires_at'])->toArray());"
    ```

13. Open the `authorization_url` in a browser and sign in with the Google test account.
14. Confirm the browser ends at the `return_url` carrying `code` and `state` in the query string, and that `state` is the value from step 11. Copy the `code`.
15. In that same browser, open `http://127.0.0.1:8000/home` and confirm you are **not** signed in. A handoff must leave no Meridian session in the system browser.
16. Exchange the code for a token:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/session -H "Content-Type: application/json" -H "Accept: application/json" -d '{"code":"PASTE-CODE","code_verifier":"PASTE-VERIFIER","client_name":"QA web client","device":{"id":"PASTE-DEVICE-UUID","label":"QA browser profile","platform":"browser","public_key":"'"$(python -c "import base64,os; print(base64.b64encode(os.urandom(32)).decode())")"'"}}'
    ```

17. Confirm the response is `201 Created` with `token`, `token_type: Bearer`, an `expires_at` roughly six weeks out, `provider: google`, the Google account's identity, and a `device` block echoing the identifier, label, and platform.
18. Submit the same `code` a second time and confirm it is refused with `401` and `"reason":"invalid_handoff"`.
19. Repeat steps 10-17 with `client=mobile`. At step 14 the browser will report an unknown protocol rather than following `org.meridian.field://auth/handoff?...` — that address is what the operating system hands to the packaged application on a real device, and reading it from the address bar or the browser's network log is how you obtain the code here. Use a second device identifier with `"platform":"android"`.
20. Repeat once more with `client=desktop` and confirm the return address is `org.meridian.kiosk://auth/handoff?...`. Use a third device identifier with `"platform":"electron"`.
21. Confirm holding the code is not sufficient. Start a fresh handoff, complete it in the browser, and exchange the code with a **different** verifier:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/session -H "Content-Type: application/json" -H "Accept: application/json" -d '{"code":"PASTE-CODE","code_verifier":"PASTE-A-DIFFERENT-VERIFIER","device":{"id":"PASTE-DEVICE-UUID"}}'
    ```

    Confirm it is refused with `401` and `"reason":"invalid_code_verifier"`, then exchange the same code with the correct verifier and confirm it still succeeds — a wrong verifier must not cost the real client its sign-in.
22. Confirm a canceled consent comes back to the client. Start a handoff, and at the Google consent screen deny access. Confirm the browser is returned to the client's address carrying `error=provider_denied`, no `code`, and the same `state`.
23. Confirm a client target this node does not offer is refused. Set `MERIDIAN_API_HANDOFF_RETURN_MOBILE=` (empty) in `apps/server/.env`, run `php artisan config:clear`, and start a handoff with `client=mobile`. Confirm `503` with `"reason":"client_target_not_configured"`. Restore the setting afterwards.
24. Confirm an unrecognized client target is refused: start a handoff with `client=television` and confirm `422` with `"reason":"unknown_client_target"`.
25. Confirm the issued tokens are audited by path, with nothing usable in the entry:

    ```bash
    php apps/server/artisan tinker --execute="App\Models\AuditEvent::query()->where('action','api_token.issued')->get(['entity_id','actor_device_id','reason'])->each(fn (\$e) => print_r(\$e->toArray()));"
    ```

## Expected results

- `/login` is reachable without authentication.
- The Google sign-in link starts a state-protected OAuth redirect.
- Google callback completes sign-in only when Google returns a verified email.
- A new user record and `auth_identities` row with provider `google` are created for first-time verified Google logins.
- Repeat login with the same verified email reuses the same user account.
- Missing Google OAuth configuration returns to `/login` with an error instead of redirecting externally.
- Canceled, invalid, or unverified-email Google callbacks do not create a session.
- `GET /api/auth/google/start` answers the client rather than redirecting a browser, and refuses an unrecognized client target with `422`, a client target with no configured return address with `503`, and a request with no PKCE challenge with `422`.
- A completed handoff returns the browser to the address this node has configured for that client target, and to no other address; the mobile and desktop targets are custom schemes the operating system hands to the packaged application.
- The value the browser carries back is a single-use exchange code, not a token. `POST /api/auth/session` exchanges it for a device-bound bearer token once; a second attempt is refused with `invalid_handoff`.
- An exchange presented without the verifier the handoff started with is refused with `invalid_code_verifier` and does not spend the code.
- A denied consent screen returns the client an `error` reason and no code, and the same works for an unverified Google email and a disabled Meridian account.
- Completing a handoff leaves no web session in the system browser, and an ordinary `/login` sign-in through the same callback still establishes one.
- Neither the handoff state nor the exchange code is stored in readable form; `api_auth_handoffs` holds only keyed hashes of both.
- Tokens issued through the handoff are audited as `api_token.issued` with reason `provider_handoff`, naming the token and device by identifier only.

## Evidence to capture

- Screenshot of the login screen showing the Google option.
- Screenshot of Google account selection or consent page with sensitive account details redacted.
- Screenshot of the signed-in `/home` placeholder.
- Database or admin inspection showing one `auth_identities` row for provider `google`.
- The `201` start responses for the web, mobile, and desktop client targets, showing the three different return addresses.
- The browser's address bar or network log showing the return leg for each client target, including the custom-scheme addresses the browser declines to follow.
- The `201`, `401 invalid_handoff`, and `401 invalid_code_verifier` responses from `POST /api/auth/session`, with token values redacted.
- A `select provider, client_target, redirect_uri, state_hash, exchange_code_hash, status, failure_reason from api_auth_handoffs` listing showing hashed credentials, one completed handoff per client target, and the failed ones with their reasons.

## Failure notes

- If Google rejects the redirect, confirm `GOOGLE_OAUTH_REDIRECT_URI` exactly matches both `.env` and the Google OAuth client.
- If Meridian returns to `/login` with a configuration error, confirm all three Google OAuth environment variables are set and clear cached config if needed.
- If a handoff comes back with `error=handoff_expired`, the round trip took longer than `MERIDIAN_API_HANDOFF_EXPIRES_MINUTES`; start it again rather than reusing the address.
- If the browser lands on `/login` instead of the client's return address, the state did not match a stored handoff — confirm you opened the `authorization_url` from the same start response you are exchanging against.
- Automated tests cover provider fakes, unverified email rejection, state mismatch rejection, disabled-user rejection, and the full handoff per client target without requiring live Google credentials.
