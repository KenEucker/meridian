# QA-AUTH-02: Google OAuth Login

## Purpose

Verify that Meridian central authentication supports Google OAuth without password login, requires a verified Google email, resolves each login to a global user account by email, and establishes a web session.

## Requirements covered

- Technical spec: Section 11.1 Authentication providers
- Data/API spec: Section 10.3 Users and Authentication
- UI Implementation Contract: Section 12.1 (`auth.login`, `auth.provider-callback`)
- UI Implementation Contract: Section 18.2 Authentication and Re-authentication

## Environment

- Local development environment.
- PHP 8.5.x and Composer available.
- SQLite or PostgreSQL database configured for `apps/server`.
- A Google OAuth test client configured for a local redirect URL.

## Personas

- Volunteer/applicant using a Google account with a verified email
- Returning user with an existing Meridian account for the same verified email
- Human reviewer

## Setup data

- No seed data required.
- Set `APP_URL=http://127.0.0.1:8000` in `apps/server/.env`.
- Set `GOOGLE_OAUTH_CLIENT_ID` to the Google OAuth test client ID.
- Set `GOOGLE_OAUTH_CLIENT_SECRET` to the Google OAuth test client secret.
- Set `GOOGLE_OAUTH_REDIRECT_URI=http://127.0.0.1:8000/login/google/callback`.
- In the Google OAuth client, allow the same redirect URI.

## Steps

1. From `apps/server`, start the development server: `php artisan serve`.
2. Open `http://127.0.0.1:8000/login` and confirm the login screen shows `Continue with Google`.
3. Select `Continue with Google`.
4. Confirm the browser leaves Meridian for Google account selection.
5. Choose a Google test account with a verified email and approve the requested profile/email scopes.
6. Confirm Google redirects back to Meridian.
7. Confirm the browser redirects to `/home` and shows the signed-in placeholder page with the Google account email.
8. Sign out or use a private window, then repeat steps 2-7 with the same Google account and confirm the existing account is reused.

## Expected results

- `/login` is reachable without authentication.
- The Google sign-in link starts a state-protected OAuth redirect.
- Google callback completes sign-in only when Google returns a verified email.
- A new user record and `auth_identities` row with provider `google` are created for first-time verified Google logins.
- Repeat login with the same verified email reuses the same user account.
- Missing Google OAuth configuration returns to `/login` with an error instead of redirecting externally.
- Canceled, invalid, or unverified-email Google callbacks do not create a session.

## Evidence to capture

- Screenshot of the login screen showing the Google option.
- Screenshot of Google account selection or consent page with sensitive account details redacted.
- Screenshot of the signed-in `/home` placeholder.
- Database or admin inspection showing one `auth_identities` row for provider `google`.

## Failure notes

- If Google rejects the redirect, confirm `GOOGLE_OAUTH_REDIRECT_URI` exactly matches both `.env` and the Google OAuth client.
- If Meridian returns to `/login` with a configuration error, confirm all three Google OAuth environment variables are set and clear cached config if needed.
- Automated tests cover provider fakes, unverified email rejection, state mismatch rejection, and disabled-user rejection without requiring live Google credentials.
