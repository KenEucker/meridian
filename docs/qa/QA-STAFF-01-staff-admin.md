# QA-STAFF-01 Staff Admin

## Purpose

Verify that an authorized admin can manage staff profile records and view organization-level staff status records in Orchid without exercising department or team membership workflows.

## Requirements covered

- `VOL-001`
- `VOL-002`
- `VOL-004`
- `VOL-005`
- Data/API spec section 10.4
- Meridian Alpha 1 task M4.5

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated.
- Orchid admin reachable at the configured development admin route.

## Personas

- Staff admin: Orchid user with `platform.index` and `platform.staff` permissions.
- Restricted admin: Orchid user with `platform.index` but without `platform.staff`.

## Setup data

- At least one organization exists.
- Create or use a staff admin account with `platform.staff` permission.
- Create or use a restricted admin account without `platform.staff` permission.

## Steps

1. Sign in to Orchid as the staff admin.
2. Open Operations, then Staff.
3. Create a staff profile with only legal name and email.
4. Confirm the staff profile appears in the Staff list.
5. Open the staff profile detail.
6. Confirm organization status records appear inside the Staff detail screen with organization and readable status labels.
7. Confirm there is no separate Organization Staff navigation item or separate Organization Staff screen.
8. Edit the staff profile and confirm legal name and email are required while preferred name, handle, phone, city, state, date of birth, and emergency contact fields may be blank.
9. Archive and restore the staff profile.
10. Sign in as the restricted admin and attempt to open Staff.

## Expected results

- Staff profile creation requires legal name and email, allows other profile fields to be completed later, and rejects invalid email/date values.
- Organization-level status is shown as a detail of the staff profile, not as a separate kind of staff.
- A staff profile can have an organization status without department or team membership.
- Staff profile archive/restore preserves the record.
- Restricted admin receives a denied response for Staff.

## Evidence to capture

- Screenshot of the Staff list showing the created profile.
- Screenshot of the Staff detail screen showing organization status details.
- Note the status label used during testing.
- Note the denied response observed for the restricted admin.

## Failure notes

- If the Staff menu item is missing for the staff admin, verify the account has `platform.staff`.
- If a separate Organization Staff screen appears, stop and report terminology regression because organizational staff is not a product concept.
- If department or team membership controls appear in this flow, stop and report scope leakage because those belong to M4.6.
