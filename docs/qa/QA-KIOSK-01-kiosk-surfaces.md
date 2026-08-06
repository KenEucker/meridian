# QA-KIOSK-01: Kiosk Surfaces

## Purpose

Verify the six Kiosk screens the UI implementation contract names, and the rule that decides whether any of them are reachable: a Meridian Kiosk works in one organization and one event, it is told which by the node, and until it is told it sits in setup rather than working it out for itself.

Four things are being proved, and the last two are the ones a shared machine in a field actually depends on.

1. **A Kiosk that does not know where it is says so.** UI-020 names five things it must not infer its context from — the viewport, the network, the authenticated user, the last route, and cached event data — and the point of the check is that a machine with a perfectly good session naming a perfectly good event still goes to setup.
2. **Pinning is somebody's, and audited.** An organizer moves a workstation to the next event from the machine itself; the first pin, and the organization, are God Mode's, because a workstation nobody has pinned cannot be signed in to at all.
3. **A handover is explicit and honest about what it costs.** Ending a session abandons unsaved work and keeps queued work, and the screen says which is which before it happens rather than after.
4. **Re-authentication confirms a person, not a session.** A valid code belonging to somebody else confirms nothing and hands nothing over.

## Requirements covered

- `UI-017`, `UI-019`, `UI-020`, `UI-021`, `UI-022`, `UI-023`
- `AUTH-026`, `AUTH-027`, `AUTH-029`, `AUTH-030`
- `SLB-003`, `SLB-004`, `SLB-029`
- Technical spec: Section 13.1 Definition
- Technical spec: Section 13.2 Shared workstation login
- Technical spec: Section 13.3 Shared workstation session behavior
- Data/API spec: Section 12.3 `shared_workstations`
- Data/API spec: Section 12.6 `shared_workstation_sessions`
- UI Implementation Contract: Section 12.8 Kiosk Screens
- UI Implementation Contract: Section 18 Kiosk and Trusted Workstation Contract
- UI Implementation Contract: Section 19.3 Meridian Kiosk permission denial
- Kiosk and field hardware UX guide: Sections 3, 4.4, 5, 6

## Environment

- Local development environment.
- PHP 8.5.x, Composer, Node 24, and pnpm 11 available.
- SQLite or PostgreSQL database configured for `apps/server`.
- Server running with `pnpm run server:dev`.
- The Kiosk artifact running with `pnpm run client:dev:kiosk`, which is the only mode these screens exist in.
- A second browser profile or private window for the God Mode console, so the Kiosk is not sharing a session with it.

## Personas

- God Mode operator (registers the workstation and makes the first pin)
- Organizer or Lead Organizer holding `organization.events.manage` in the workstation's organization
- Department Logistics holder or department lead working the desk
- Staff member with no attendance authority
- Human reviewer

## Setup data

- One organization with two events, so a workstation has somewhere to be moved to. Name them distinctly, for example `Emberfall 2026` and `Winterlight 2027`.
- At least one department participating in the first event, with a shift running now and one person assigned to it who has not checked in.
- At least one department of the same organization that does **not** participate in the first event, to prove the department pin is refused for it.
- One trusted shared workstation row with a device behind it, `trusted` true, `revoked_at` null, and `organization_id`, `event_id`, and `department_id` all null. That is the unpinned state, and it is the state this script starts in. Create it with `php artisan tinker` from an existing `devices` row; registering the device is node pairing and is not part of this script.
- A login code for the organizer at that workstation, generated from the God Mode **Workstation Login Codes** screen once the workstation is pinned.

## Steps

### An unpinned Kiosk enters setup

1. In the Kiosk, open the browser console and set the workstation identity: `localStorage.setItem('meridian.workstation.id', '<workstation-uuid>')`, then reload.
2. Confirm the Kiosk lands on `/kiosk/setup`, names the workstation, and reports the organization and event as **Not pinned**.
3. Type `/kiosk`, `/kiosk/sign-in`, `/kiosk/shift-board`, `/kiosk/switch-user`, and `/kiosk/confirm` into the address bar in turn. Confirm each one lands back on `/kiosk/setup`.
4. Open `/kiosk/timed-out` and confirm it renders. It is the one surface that stays reachable with no context and no session.
5. Sign in to the Meridian Field or Admin client in another window as any user associated with the first event, so a session document naming an event exists on the machine. Return to the Kiosk, reload, and confirm it is still in setup. A session that resolves an event is not a pinned context.
6. Read the setup screen's pinned-context block and confirm it names God Mode as the authority for a workstation that has never been pinned, and offers no event selector.

### God Mode makes the first pin

7. In the God Mode console, open **Shared Workstations**. Confirm the workstation is listed with **In setup** as its Kiosk state.
8. In the pinning form, choose the workstation, the organization, `Emberfall 2026`, and the department that does **not** participate in that event. Submit.
9. Confirm the form is refused with a message naming the department and saying it does not work this event, and that the list still shows **In setup**.
10. Submit again with no department. Confirm the toast reports the pin, the list shows **Ready**, and the row names the organization and event.
11. Confirm an `audit_events` row exists with action `shared_workstation.context_pinned`, the operator as the actor, and `orchid` as the source:

    ```bash
    php apps/server/artisan tinker --execute="dump(\App\Models\AuditEvent::where('action','shared_workstation.context_pinned')->latest()->first()->only(['actor_user_id','organization_id','event_id','source_context','before','after']));"
    ```

12. Return to the Kiosk and reload. Confirm it leaves setup and lands on `/kiosk/sign-in`, and that the screen names the event.

### Signing in and the workstation dashboard

13. Generate a login code for the organizer at this workstation from **Workstation Login Codes**, and enter it at the Kiosk.
14. Confirm the Kiosk lands on `/kiosk`, the session bar shows the organizer's name as its largest element, and the dashboard names the organization and event in words.
15. Confirm the dashboard offers **Shift board**, **Switch user**, and **Workstation setup**.

### An organizer moves the workstation

16. Open **Workstation setup** from the dashboard. Confirm the event selector is offered now that somebody with `organization.events.manage` is signed in, and that it lists both events.
17. Choose `Winterlight 2027` and a department that works it, and pin.
18. Confirm the Kiosk locks: the pinned context changed under the session, so the session it was signed in to is over. Confirm the session bar is gone.
19. In the console, confirm the session row ended as `superseded`:

    ```bash
    php apps/server/artisan tinker --execute="dump(\App\Models\SharedWorkstationSession::latest('started_at')->first()->only(['ended_at','ended_reason']));"
    ```

20. Confirm a second `shared_workstation.context_pinned` audit row exists, this time with `api` as the source and the organizer as the actor.
21. Sign in again with a fresh code and repeat from the Kiosk setup screen to move the workstation back to `Emberfall 2026` and its participating department, so the shift board has something to show.

### The shift board

22. Sign in at the Kiosk as the Department Logistics holder or department lead for the pinned department.
23. Open **Shift board**. Confirm the shift running now is listed with its window and team, and the assigned person under it with their attendance state.
24. Press **Check in** for that person. Confirm the row updates and the screen says the operation was recorded on this workstation and will be sent when the node is reachable.
25. Confirm the operation reached the node: the person's attendance state on the department Logistics Desk shows checked in.
26. Stop the server. Press **Check out** for the same person. Confirm the action is accepted rather than refused, and the board reports it as queued.
27. Start the server again and confirm the queued operation syncs and the attendance record moves, without a duplicate.
28. Sign out, and sign in as the staff member with no attendance authority. Open **Shift board** and confirm no check-in, check-out, or no-show control is present at all, and that the screen says the action is not available for the current user and offers kiosk home or switching users.

### Switching users

29. Sign in as any user. Queue one operation — a Field Report or an attendance operation — with the server stopped, so there is something in the outbox.
30. Open **Switch user**. Confirm it names who is signed in, says unsaved work is abandoned, and states the number of queued commands that stay on the workstation.
31. Press **End session and switch user**. Confirm the Kiosk lands on `/kiosk/sign-in`.
32. Sign in as a different user and confirm the queued command is still queued.
33. With that session live, type `/kiosk/sign-in` into the address bar. Confirm it lands on `/kiosk` instead: there is no quiet handover.

### Re-authentication

34. With a session live, open `/kiosk/confirm?return=kiosk.shift-board`.
35. Generate a login code for a **different** user at this workstation, and enter it. Confirm the screen states the code belongs to a different user and points at switching users, that the session bar still shows the original user, and that the field is cleared.
36. Generate a fresh code for the **signed-in** user and enter it. Confirm the screen confirms and lands on the shift board.
37. Confirm the session recorded it:

    ```bash
    php apps/server/artisan tinker --execute="dump(\App\Models\SharedWorkstationSession::whereNull('ended_at')->latest('started_at')->first()->only(['user_id','reauthenticated_at']));"
    ```

38. Confirm an `audit_events` row exists with action `shared_workstation_session.reauthenticated` naming the confirming user and the code it was confirmed with, and no code value anywhere in the payload.

### Timeout

39. Leave the Kiosk untouched. Confirm it warns before the five minutes are up and offers a control that continues the session.
40. Press the control and confirm the countdown resets without anything being re-entered.
41. Leave it untouched again until it times out. Confirm it lands on `/kiosk/timed-out`, that the screen holds no name, event, or record, and that it reports the queued commands as still present.

## Expected results

- A workstation with no pinned organization and event puts its Kiosk in setup, and every Kiosk route but setup and safe timeout lands there.
- A client session that already resolves an event does not become a pinned context. Neither does a cached department selection, the last route, or anything else on the device.
- `/kiosk/timed-out` is reachable with no pinned context and no session, and holds nothing about anybody.
- The setup screen names the machine, names what it is pinned to, and — for a workstation nobody has pinned — names God Mode as the authority rather than offering a form that could only be refused.
- God Mode pins a workstation to an organization, an event, and optionally a department. A department that does not work the chosen event is refused with a message naming it.
- An organizer moves a pinned workstation between events from the Kiosk setup screen. A Staff Coordinator and an organizer of another organization cannot.
- Every pin is audited as `shared_workstation.context_pinned` with the before and after context, the actor, and the source it was made from.
- Pinning ends the live session as `superseded`, because that session was signed in to the previous context.
- The Kiosk dashboard names the organization and event in words, not identifiers.
- The shift board lists the shifts running at the pinned department with the people on them, and records check-in, check-out, and no-show — including with the node unreachable, where the operation queues and syncs once without duplicating.
- A user the node grants no attendance authority sees no attendance controls at all, and is told so with a return path rather than left looking at buttons that refuse.
- Switching users states what is abandoned and what is kept, with the queued count, before it ends the session. Code entry stays unreachable while a session is live.
- Re-authentication confirms the signed-in user with a fresh code, records `reauthenticated_at`, and is audited. A valid code for another user is refused, confirms nothing, and hands nothing over.
- The timeout warns first, continuing slides the window without re-entry, and a timeout lands on the safe surface with queued work intact.

## Evidence to capture

- Screenshot of `/kiosk/setup` on an unpinned workstation, and of the address bar after typing `/kiosk` and landing back on it.
- Screenshot of the Kiosk still in setup with a client session naming an event present on the same machine.
- Screenshot of the God Mode **Shared Workstations** list showing **In setup**, and again showing **Ready**.
- Screenshot of the department refusal in step 9.
- The audit output from steps 11 and 20, and the session output from step 19.
- Screenshot of the Kiosk dashboard showing the organization and event.
- Screenshot of the shift board with a shift and its roster, and of the same board for a user with no attendance authority.
- The queued command visible while the server is stopped, and the attendance record after it syncs.
- Screenshot of the switch-user screen showing the queued count.
- Screenshots of the re-authentication refusal and the confirmation, and the outputs from steps 37 and 38 with no code value present.
- Screenshot of the timeout warning and of `/kiosk/timed-out`.

## Failure notes

- If the Kiosk never leaves setup after a pin, the read is failing rather than the pin. `GET /api/kiosk/workstations/<id>` answers only for a workstation that is trusted and whose device is not revoked; anything else is a 404, deliberately, so an unknown identifier and a decommissioned machine are answered alike.
- If the Kiosk stays in setup with the server stopped and nothing stored, that is correct. It keeps the last answer the node gave about the machine, and a machine that has never had one has nothing to keep — remembering is allowed, guessing is not.
- If the setup screen offers no event selector while an organizer is signed in, the node refused the options read. It answers to `organization.events.manage` in the workstation's own organization; an organizer of a different organization is nobody in particular here.
- If pinning is refused with `workstation_organization_unpinned`, the workstation has no organization and the change is God Mode's. That is the rule, not a bug: there is no organization for an organizer's authority to be held in.
- If a login code cannot be generated for the workstation, check its pinned event resolves to an event this node holds. A code is scoped to a pinned event, which is why an unpinned workstation cannot be signed in to.
- If code entry is refused with `login_code_rate_limited`, the per-workstation entry limit has been reached by repeated QA attempts. A successful entry clears the counter; otherwise wait out the window.
- If re-authentication is refused with `reauthentication_user_mismatch` when you believe the code was for the right person, check which user the code was generated for. The code is spent either way — that is the same decision code entry makes for a disabled account, and a single-use credential is not handed back for another try.
- If the shift board shows no shifts, check the shift's window against the node's clock. The board shows what is running now, within the same window the Logistics Desk uses.
- No privileged action requires re-authentication yet. `reauthenticated_at` is recorded and reported; which actions demand a recent confirmation, and how recent, belongs to those actions rather than to this surface.
