# QA-AUTH-04: On-site Device Sign-in

## Purpose

Verify the path a person actually walks up to a shared workstation with: the phone in their pocket. QA-AUTH-01 exercises workstation login codes only through the God Mode console, which is why none of this has been checkable by hand until now.

Five things are being proved.

1. **Scan-to-sign-in is a two-tap interaction.** The locked Kiosk presents a sign-in request as a QR beside its own name and workstation code; a phone holding a session scans it, confirms the named workstation and event, grants, and the Kiosk opens the session — with no typing at anybody's end.
2. **A photograph of the screen buys nothing but your own sign-in.** The QR carries only public identifiers. The pickup secret that later collects the session key never leaves the machine that opened the request, so a shoulder-surfed code lets somebody sign *themselves* in there and never take the session.
3. **The typed fallbacks work when the camera does not.** The workstation's displayed `short_code` resolves it on the phone and issues an ordinary targeted login code; a code generated with no target at all binds to the first trusted workstation it is entered at, within its event.
4. **A foreign node is refused with both identities named.** A phone pointed at one node, scanning a workstation that lives on another, is told which node the device is using and which the workstation wants — rather than issuing a credential into the wrong database that fails at the keyboard with no reason given.
5. **Scan re-authentication confirms a person, not a session.** A grant from the session's own user stamps `reauthenticated_at` exactly as a typed code does; a grant from anybody else is refused on their own phone and hands nothing over.

(This script carries the ID QA-AUTH-04 rather than the QA-AUTH-02 the development plan names, because QA-AUTH-02 was already the Google OAuth login script when the plan was written.)

## Requirements covered

- `AUTH-026`, `AUTH-027`, `AUTH-028`, `AUTH-031`, `AUTH-032`, `AUTH-033`, `AUTH-034`, `AUTH-035`, `AUTH-036`, `AUTH-037`
- Technical spec: Section 13.2 Shared workstation login, Untargeted codes
- Technical spec: Section 13.4 On-site device sign-in
- Data/API spec: Section 12.3 `shared_workstations` (`short_code`)
- Data/API spec: Section 12.4 `shared_workstation_login_codes`
- Data/API spec: Section 12.4A `shared_workstation_sign_in_requests`
- UI Implementation Contract: Section 12.3 `staff.workstation-code`
- UI Implementation Contract: Section 12.8 Kiosk Screens
- UI Implementation Contract: Section 18.2 Authentication and Re-authentication
- Kiosk and field hardware UX guide: Sections 4.1, 4.1A

## Environment

- Local development environment.
- PHP 8.5.x, Composer, Node 24, and pnpm 11 available.
- SQLite or PostgreSQL database configured for `apps/server`.
- Server running with `pnpm run server:dev`.
- The Kiosk artifact running with `pnpm run client:dev:kiosk` — this is the workstation.
- The Field artifact running with `pnpm run client:dev:field` in a second browser profile or private window — this is the phone. A device with a camera makes the scan steps literal; without one, the QR's decoded text can be read from the Kiosk page's SVG `aria-label` context and the confirm step driven by entering the same request through the workstation code fallback, with the scan-specific steps marked Blocked rather than skipped silently.
- A third window on the God Mode console for the audit checks.

## Personas

- Staff member holding a session in the Field client ("the phone")
- A second staff member holding their own session, for the wrong-user grant
- God Mode operator, for provisioning and the audit trail
- Human reviewer

## Setup data

- A seeded database (`pnpm run server:migrate:seed`), signed in as any seeded staff persona in the Field client.
- A trusted, pinned shared workstation: `php artisan meridian:shared-workstation` provisions one against the seeded event and prints its id.
- **The workstation identifier**, which the Kiosk setup screen asks for. Read it from the **Identifier** column on the God Mode **Shared Workstations** screen, or from the provisioning command's output. It is not a credential (AUTH-030) — signing in still needs a login code issued to a named person at a trusted workstation.
- Set the Kiosk's identity by typing that identifier into the **Workstation identifier** field on `/kiosk/setup` and pressing **Save and check with the node**. (`pnpm run kiosk:workstation` injects it through the Electron preload instead, so the field is only needed for a browser-run Kiosk.)
- Note the workstation's `short_code`: it is displayed on the locked Kiosk beside the QR, and in the **Workstation code** column on the same God Mode screen.
- **After any `migrate:fresh --seed`, both values change.** Every UUID regenerates, so a Kiosk holding the old identifier reports that the node knows no such workstation, and the identifier has to be re-read and re-entered.

## Steps

### The locked Kiosk presents a scannable request

1. Open the Kiosk. Confirm the locked screen shows a QR, the workstation's name, and its workstation code, with the typed login-code field standing below — not instead.
2. Wait past the request lifetime (two minutes by default) without scanning. Confirm the square is replaced rather than left rendered: the QR redraws and the screen never shows a spinner or an empty panel.
3. Stop the server (`Ctrl+C` on `server:dev`). Reload the Kiosk. Confirm the scan panel is replaced by a plain statement that scan-to-sign-in is unavailable and a login code can be entered instead, with the typed field still standing. Restart the server and confirm the QR returns within a few ticks without a reload.

### Scan, confirm, grant

4. In the Field client, open **Me → Workstation Sign-in**. Confirm the surface offers the scan control, the workstation-code field, and the no-target code control.
5. Scan the Kiosk's QR with the phone. Confirm a confirmation names the workstation and its event and asks whether to sign in as yourself — no user field is offered.
6. Confirm. Within a few seconds the Kiosk opens a session naming the phone's user in the session bar. Confirm the phone shows a done state, and that the phone's own session is unchanged — no new token, no sign-out.
7. On the Kiosk, end the session. Scan the *same* QR again if it is still displayed anywhere (a photograph works): confirm the grant is refused — a request grants once — and a fresh QR is presented for the next person.

### The typed fallbacks

8. On the phone, enter the workstation's `short_code` in the workstation-code field. Confirm a login code is issued, displayed once, naming the workstation it is for, with a plain statement that it will not be shown again.
9. Navigate away and back. Confirm the code is gone and not retrievable.
10. Type that code at the Kiosk (generate a fresh one if the display was dismissed before it was written down — that is the point of step 9). Confirm it signs the phone's user in. End the session.
11. On the phone, generate a code with no workstation named. Confirm the display says it works at any trusted workstation in this event and binds to the first one it is used at.
12. Type it at the Kiosk. Confirm it signs in. In the God Mode **Workstation Login Codes** list, confirm the spent code now names the workstation it was used at rather than reading unbound.

### Foreign-node refusal

13. Craft a foreign-node scan: on the phone's Workstation Sign-in surface, scan (or hand-enter through the browser console as a scanned text) a QR whose node identifier is not this node's — the recorded text of a QR from another install, or the Kiosk's QR text with the `n=` value altered.
14. Confirm the refusal names both nodes — which node this device is using, and which the workstation wants — and that nothing was issued: no grant reaches the node's audit trail, and the Kiosk stays locked.

### Request expiry and re-open

15. Scan a fresh Kiosk QR and reach the confirmation on the phone, then wait past the request lifetime before granting. Confirm the grant is refused as expired and the phone says to ask the workstation for a fresh code — and that the Kiosk has already replaced the expired request on its own.

### Scan re-authentication

16. Sign in at the Kiosk as the phone's user. Navigate to `/kiosk/confirm`. Confirm the screen presents a QR beside the typed code field, and the lede names the signed-in user.
17. With the *second* staff member's session on another device (or another browser profile in the Field client), scan the confirmation QR. Confirm the grant is refused on that person's own device and the Kiosk session is unchanged — no confirmation, no handover, still the first user's session.
18. Scan the same QR as the session's own user and grant. Confirm the Kiosk reports the confirmation and returns to what it was doing.
19. In the God Mode audit trail, find the two `shared_workstation_session.reauthenticated` shapes: one from this scan, and one from a typed code (perform a typed re-authentication to produce it). Confirm both carry the same action and stamp, distinguished only by whether they name a login code or a sign-in request.

## Expected results

- The locked Kiosk always offers a working path: a live QR, or a plain statement plus the typed field. Never a stale square, a spinner, or a blank panel.
- A granted request signs in exactly the granting user, at exactly the workstation that opened it, once. The phone's own session is untouched.
- The `short_code` path issues a targeted code; the no-target path issues an unbound code that binds where first used; both display once and say so.
- A foreign-node scan is refused with both node identities named and issues nothing.
- An expired request can be neither granted nor collected, and the Kiosk replaces it unprompted.
- Re-authentication accepts only the session's own user's grant, stamps `reauthenticated_at` identically to the typed path, and hands nothing over on refusal.
- Opening, granting, and collection appear in the audit trail by request identifier; no pickup secret or session key appears anywhere in it.

## Evidence to capture

- A photo or screenshot of the locked Kiosk with QR, name, and workstation code.
- The phone's confirmation screen naming workstation and event, and the done state.
- The one-time code display with its shown-once statement, and the same surface after re-render with the code gone.
- The foreign-node refusal naming both nodes.
- The God Mode login-code list showing a spent unbound code naming its redeeming workstation.
- Audit rows for `shared_workstation_sign_in_request.opened` / `.granted` / `.collected` and both `shared_workstation_session.reauthenticated` shapes.

## Failure notes

- A QR that scans but the phone calls "not a Meridian workstation sign-in code" is a payload drift between `workstationSignInQr.ts`'s builder and parser — they live in one module precisely so this cannot happen silently.
- A Kiosk stuck on "pending" after a successful grant usually means the collect poll is being throttled: check the route throttle and the request's `collected_at` in the database to see which side stopped.
- A foreign-node refusal that names only one node means the device could not resolve its own identity: check `GET /api/health` for `node_id`/`node_name`.
- If the scan steps cannot be performed for want of a camera, record them as Blocked with the device model, not as Pass by fallback: the typed path passing is a different fact.
