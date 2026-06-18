# QA-AUTH-03: Discord OAuth Login

## Purpose

Verify that Meridian central authentication supports Discord OAuth without password login, requires a verified Discord email, resolves each login to a global user account by email, and establishes a web session.

## Requirements covered

- Technical spec: Section 11.1 Authentication providers
- Data/API spec: Section 10.3 Users and Authentication
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
- In the Discord OAuth application, allow the same redirect URI.

## Steps

1. From `apps/server`, start the development server: `php artisan serve`.
2. Open `http://127.0.0.1:8000/login` and confirm the login screen shows `Continue with Discord`.
3. Select `Continue with Discord`.
4. Confirm the browser leaves Meridian for Discord authorization.
5. Choose a Discord test account with a verified email and approve the requested identify/email scopes.
6. Confirm Discord redirects back to Meridian.
7. Confirm the browser redirects to `/home` and shows the signed-in placeholder page with the Discord account email.
8. Sign out or use a private window, then repeat steps 2-7 with the same Discord account and confirm the existing account is reused.

## Expected results

- `/login` is reachable without authentication.
- The Discord sign-in link starts a state-protected OAuth redirect.
- Discord callback completes sign-in only when Discord returns a verified email.
- A new user record and `auth_identities` row with provider `discord` are created for first-time verified Discord logins.
- Repeat login with the same verified email reuses the same user account.
- If the verified Discord email matches an existing Meridian account from magic link or Google OAuth, Discord attaches to that same user.
- Missing Discord OAuth configuration returns to `/login` with an error instead of redirecting externally.
- Canceled, invalid, or unverified-email Discord callbacks do not create a session.

## Evidence to capture

- Screenshot of the login screen showing the Discord option.
- Screenshot of Discord authorization with sensitive account details redacted.
- Screenshot of the signed-in `/home` placeholder.
- Database or admin inspection showing one `auth_identities` row for provider `discord`.

## Failure notes

- If Discord rejects the redirect, confirm `DISCORD_OAUTH_REDIRECT_URI` exactly matches both `.env` and the Discord OAuth application.
- If Meridian returns to `/login` with a configuration error, confirm all three Discord OAuth environment variables are set and clear cached config if needed.
- Automated tests cover provider fakes, unverified email rejection, state mismatch rejection, same-email Google account linking, and disabled-user rejection without requiring live Discord credentials.
