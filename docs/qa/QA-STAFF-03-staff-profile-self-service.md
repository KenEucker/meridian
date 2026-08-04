# QA-STAFF-03: Staff Profile Self-Service

## Purpose

Verify that a staff member reads their own profile on **Me**; edits their own preferred name, phone, and city/state from **Edit profile** with the change applying immediately and without review; changes their handle directly twice and by review after that; submits a profile picture that waits for a reviewer while their current picture stays in force; and cannot change legal name, email, or date of birth at all — with the server refusing a submitted identity field rather than silently dropping it, and every applied change audited with before and after values.

## Requirements covered

- `VOL-009`: the staff profile field set the surface reads.
- `VOL-010`: handle shown as the radio/operational handle.
- `VOL-013`: active staff upload, replace, and remove one current profile picture; visibility follows staff profile visibility.
- `VOL-014`: a staff profile surface on which a staff member maintains their own profile fields and picture, with picture actions online-only.
- `VOL-015`: preferred name, phone, and city/state change immediately and without review.
- `VOL-016`: legal name, email, and date of birth are not self-editable and remain an assisted path.
- `VOL-017`, `VOL-018`: the first two applied handle changes are self-service and later ones are reviewed; a first handle is not a change; only applied changes consume the allowance.
- `VOL-019`, `VOL-020`: reviewers are organizers and Staff Coordinators of the staff member's organization; a handle collision is named to the reviewer rather than blocking the decision.
- `VOL-021`, `VOL-022`, `VOL-023`: picture submissions are reviewed change requests whose pending image is restricted; approval promotes it and rejection discards it; removal is immediate.
- `VOL-024`: one outstanding request per kind, withdrawable by its submitter alone.
- `VOL-025`: a rejection carries the reason the reviewer gave to the submitter.
- `VOL-026`: self-service profile changes and change request decisions audited with actor and the previous and new value of each changed field.
- Data/API spec sections 5.2 and 10.4: `GET /api/me/profile`, `GET /api/staff-profile-change-requests`, the seven profile commands, `staff_profile_change_requests`, and the staff self-service field rules.
- Technical spec section 18A: profile picture ownership, eligibility, limits, and processing.
- UI implementation contract section 12.3: `staff.me`, `staff.profile-edit`.
- Meridian Alpha 1 tasks M18.20, M18.20A, M18.20B, M18.20C.

## Environment

- Development server environment with migrated, seeded database (`php artisan migrate:fresh --seed`)
- Shared Meridian client (`apps/client`)
- One authenticated staff user (any seeded persona with a staff profile, for example Vera Staff)
- For API checks, authenticated requests against `/api/me/profile` and `/api/commands/update-my-profile`

## Personas

- Vera Staff (or any staff member holding no roles): the self-service editor
- Olive Organizer: the reviewer of handle and picture change requests
- Sam Shiftlead: a second staff member, for the cross-profile and third-party refusal checks
- Gwen Godmode: audit trail verification in the God Mode console

## Setup data

- The seeded development scenario: Vera Staff holds a staff profile with a legal name, email, handle, phone, and city/state on record, and is `active` in the organization
- A second seeded staff member (Sam Shiftlead) whose `staff_id` is known
- Two image files to hand: one ordinary JPEG or PNG portrait, and one file over 10 MB or of an unsupported type (a PDF will do)

## Steps

### Profile fields

1. Sign in as Vera Staff and open **Me** (`/staff/me`).
2. Confirm the personal details show Handle, Phone, and City/State from Vera's record, and that the page offers an **Edit Profile** link.
3. Open **Edit Profile** (`/staff/me/edit`).
4. Confirm preferred name, phone, city, and state render as editable inputs prefilled from the record.
5. Confirm legal name, email, date of birth, and emergency contact are displayed read-only, with the page naming the organizer-assisted path, and no input renders for any of them.
6. Change the preferred name and city, then save.
7. Confirm the page reports the change applied immediately, and that **Me** shows the new values without any review step.
8. Using the API with Vera's token, POST `/api/commands/update-my-profile` with `{"legal_name": "Someone Else"}` and confirm a 422 naming legal name and the assisted path. Repeat for `email` and `date_of_birth`.
9. Using the API with Vera's token, POST `/api/commands/update-my-profile` with Sam Shiftlead's `staff_id` and a new `preferred_name`, and confirm a 403 refusal with Sam's record unchanged.

### The handle allowance

10. On **Edit profile**, read the note under **Your handle** and confirm it states how many direct changes remain.
11. Change the handle and confirm the page reports it took effect immediately. Repeat once more.
12. Change the handle a third time. Confirm the page reports it was submitted for review, that the handle on **Me** is still the second one, and that the section now offers **Withdraw request**.
13. Withdraw the request, then request the third change again and leave it pending.

### The picture

14. Under **Your picture**, submit the ordinary portrait image.
15. Confirm the page reports the submission is waiting for review, shows the current picture and the submitted one side by side, and no longer offers a second submission.
16. Confirm **Me** and the Logistics Window still show the *previous* picture (or no picture), not the submitted one.
17. Attempt the oversized or unsupported file. Confirm it is refused with a message naming the limit or the accepted formats.
18. Sign in as Sam Shiftlead and, using the submitted picture's URL from step 15, attempt to open it. Confirm it is refused.

### Review

19. Sign in as Olive Organizer and open the profile change request queue.
20. Confirm both of Vera's requests appear, the handle request shows the previous and requested handle side by side, and the picture request shows the current and submitted pictures side by side.
21. If another active staff member already holds the requested handle, confirm the queue names them and still allows the decision.
22. Approve the picture request. Reject the handle request with a reason.
23. Sign back in as Vera Staff and confirm the approved picture is now the one on **Me**, and that the rejected handle request left the handle unchanged with the reviewer's reason readable.
24. Sign in to the God Mode console as Gwen Godmode and open the audit log.

### Removal

25. Back as Vera Staff, remove the current picture from **Edit profile** and confirm it disappears immediately with no review step and no pending request.

## Expected results

- **Me** renders Vera's own handle, phone, city/state, and picture, not fixture data.
- The step 6 edit applies immediately: no pending state, no review, and the new values read back on **Me** and from `GET /api/me/profile`.
- Step 8's submissions are refused with 422 and a message naming the assisted path; the record still holds the original legal name, email, and date of birth afterward.
- Step 9 is refused with 403 and changes nothing on Sam's record.
- Steps 11 and 12 show the allowance working: two changes apply outright, the third waits.
- Step 13's withdrawal restores nothing, because it consumed nothing — the allowance stays at zero.
- Step 16 is the VOL-021 property: the current picture stays in force everywhere while a submission is pending.
- Step 18 is refused; a pending picture is readable only by its submitter and its reviewers.
- Step 21's collision is named to the reviewer and does not block the decision.
- Step 22's approval makes the submitted picture current and deletes the previous one; the rejection leaves the handle alone and stores the reason.
- The audit log holds `staff.profile.self_updated` for step 6, `staff.profile_change_request.*` entries for creation, decision, and withdrawal, and `staff.profile.picture_changed` / `staff.profile.handle_changed` against the staff record where a change actually applied — each with the actor and before/after values.
- No audit entry exists for the refused submissions in steps 8, 9, and 17.
- Step 25 removes the picture with no request row created.

## Evidence to capture

- Screenshot of **Me** showing the profile rows, picture, and the Edit Profile link
- Screenshot of **Edit profile** showing the editable set and the read-only identity block
- Screenshot of the pending picture state with current and submitted side by side
- Screenshot of the reviewer's queue showing the handle pair and any named collision
- Screenshot or API transcript of the 422 refusal for a submitted legal name and for the oversized upload
- Screenshot of the audit entries with their before/after values

## Failure notes

- If the identity fields save silently instead of refusing, VOL-016 is being dropped rather than enforced; stop and file.
- If the submitted picture replaces the current one before review, VOL-021 is broken in the way that matters most: the desk comparing a face to a photo would be comparing it to an unreviewed upload.
- If a withdrawn or rejected handle request restores an allowance slot, the count is being stored rather than derived; VOL-018 is unmet.
- If a third staff member can open a pending picture URL, stop and file — that is the visibility rule VOL-021 exists for.
- If the edit applies but no audit entry exists, VOL-026 is unmet even though the surface looks correct.
- If **Me** prints "Not set" for every profile row while `GET /api/me/profile` fails, the read is down rather than the record empty; check the node connection first.
