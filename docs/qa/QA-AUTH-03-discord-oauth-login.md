# QA-AUTH-03: Discord OAuth Login

## Purpose

Verify that Meridian central authentication supports Discord OAuth without password login, requires a verified Discord email, resolves each login to a global user account by email, and produces the right credential for each caller: a web session for a browser signing in at `/login`, and a bearer token returned to a client application that signed in through the system browser.

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
- A Discord OAuth test application configured for a local redirect URL.

## Personas

- Staff/applicant using a Discord account with a verified email
- Returning user with an existing Meridian account for the same verified email
- Human reviewer

## Setup data

- No seed data required.
- Set `APP_URL=http://127.0.0.1:8000` in `apps/server/.env`.
- Set `DISCORD_OAUTH_CLIENT_ID` to the Discord OAuth test application client ID.
- Set `DISCORD_OAUTH_CLIENT_SECRET` to the Discord OAuth test application client secret.
- Set `DISCORD_OAUTH_REDIRECT_URI=http://127.0.0.1:8000/login/discord/callback`.
- In the Discord OAuth application, allow the same redirect URI. The provider handoff uses the same one, so no second redirect URI is registered.
- Leave the handoff return targets at their defaults, as listed in `QA-AUTH-02`.

## Steps

1. From `apps/server`, start the development server: `php artisan serve`.
2. Open `http://127.0.0.1:8000/login` and confirm the login screen shows `Continue with Discord`.
3. Select `Continue with Discord`.
4. Confirm the browser leaves Meridian for Discord authorization.
5. Choose a Discord test account with a verified email and approve the requested identify/email scopes.
6. Confirm Discord redirects back to Meridian.
7. Confirm the browser redirects to `/home` and shows the signed-in placeholder page with the Discord account email.
8. Sign out or use a private window, then repeat steps 2-7 with the same Discord account and confirm the existing account is reused.

### Provider handoff to a client application

`QA-AUTH-02` walks the handoff in full across all three client targets. Discord uses the same mechanism through the same endpoints, so this section verifies that Discord is genuinely wired into it rather than repeating the whole walk. Generate a PKCE verifier, a challenge, and a device identifier as described there.

9. Start a handoff as the desktop application:

   ```bash
   curl -s -H "Accept: application/json" "http://127.0.0.1:8000/api/auth/discord/start?client=desktop&code_challenge=PASTE-CHALLENGE&code_challenge_method=S256"
   ```

10. Confirm the response is `201 Created`, that `authorization_url` points at `discord.com`, that `provider` is `discord`, and that `return_url` matches `MERIDIAN_API_HANDOFF_RETURN_DESKTOP`.
11. Open the `authorization_url` in a browser, authorize with the Discord test account, and confirm the browser is returned to `org.meridian.kiosk://auth/handoff?...` carrying `code` and the `state` from step 10. The browser reports an unknown protocol rather than following it; on a real workstation that address activates the packaged application.
12. In that same browser, open `http://127.0.0.1:8000/home` and confirm you are not signed in.
13. Exchange the code for a token:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/auth/session -H "Content-Type: application/json" -H "Accept: application/json" -d '{"code":"PASTE-CODE","code_verifier":"PASTE-VERIFIER","client_name":"QA desktop client","device":{"id":"PASTE-DEVICE-UUID","label":"QA workstation","platform":"electron","public_key":"'"$(python -c "import base64,os; print(base64.b64encode(os.urandom(32)).decode())")"'"}}'
    ```

14. Confirm the response is `201 Created` with `provider: discord`, the Discord account's identity, and the device the token is bound to.
15. Confirm the handoff is scoped to the provider that started it: start a Discord handoff, then open `http://127.0.0.1:8000/login/google/callback?state=PASTE-DISCORD-STATE&code=anything` in a browser. Confirm the browser returns to `/login` with an error rather than to the client's address, and that the Discord handoff is still `pending`:

    ```bash
    php apps/server/artisan tinker --execute="print_r(App\Models\ApiAuthHandoff::query()->latest()->first(['provider','client_target','status'])->toArray());"
    ```

## Expected results

- `/login` is reachable without authentication.
- The Discord sign-in link starts a state-protected OAuth redirect.
- Discord callback completes sign-in only when Discord returns a verified email.
- A new user record and `auth_identities` row with provider `discord` are created for first-time verified Discord logins.
- Repeat login with the same verified email reuses the same user account.
- If the verified Discord email matches an existing Meridian account from magic link or Google OAuth, Discord attaches to that same user.
- Missing Discord OAuth configuration returns to `/login` with an error instead of redirecting externally.
- Canceled, invalid, or unverified-email Discord callbacks do not create a session.
- A Discord handoff started by a client application returns the browser to that client's configured address carrying a single-use exchange code, which `POST /api/auth/session` exchanges for a device-bound bearer token.
- Completing a Discord handoff leaves no web session in the system browser.
- A handoff state is scoped to the provider it was started at: presenting a Discord handoff's state to the Google callback does not complete it and leaves it pending.

## Evidence to capture

- Screenshot of the login screen showing the Discord option.
- Screenshot of Discord authorization with sensitive account details redacted.
- Screenshot of the signed-in `/home` placeholder.
- Database or admin inspection showing one `auth_identities` row for provider `discord`.
- The `201` start response for the desktop client target and the browser's report of the custom-scheme return address.
- The `201` response from `POST /api/auth/session` with the token value redacted.

## Failure notes

- If Discord rejects the redirect, confirm `DISCORD_OAUTH_REDIRECT_URI` exactly matches both `.env` and the Discord OAuth application.
- If Meridian returns to `/login` with a configuration error, confirm all three Discord OAuth environment variables are set and clear cached config if needed.
- If a handoff comes back with `error=handoff_expired`, the round trip took longer than `MERIDIAN_API_HANDOFF_EXPIRES_MINUTES`; start it again rather than reusing the address.
- Automated tests cover provider fakes, unverified email rejection, state mismatch rejection, same-email Google account linking, disabled-user rejection, and the Discord handoff to the desktop client target without requiring live Discord credentials.
