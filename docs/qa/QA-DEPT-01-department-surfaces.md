# QA-DEPT-01: Department Surfaces

## Purpose

Verify the three department screens M18.30 adds to the twelve UI contract
section 12.4 already had: the department staff list, the deployment options the
Operations Center assigns staff between, and credit review for the department.

Three boundaries are what this script is really for, and each of them fails
quietly if nobody looks:

- emergency contacts reach department leads for their own department and nobody
  else (`VOL-012`, `VOL-011`), and they are **missing from the response** for
  everybody else rather than present and blank — a blank emergency contact has
  to keep meaning "none recorded" to the lead reading it;
- a deployment somebody is currently standing at cannot be archived, and the
  refusal names how many people have to be moved first;
- a credit entry reports the rate it was frozen at, not the rate its policy
  carries today (`CREDIT-004`), so re-pricing a policy must not restate a
  finished event.

## Requirements covered

- `VOL-011`: organizers hold no default access to emergency contacts.
- `VOL-012`: department leads have emergency contact access for staff in their
  department.
- `SLB-009`: the Operations Center always includes a deployment/location module
  for `department_operations` — which needs deployments in it.
- `SLB-010`: staff can be moved between deployments.
- `SLB-019`, `SLB-020`: the Planning Table stays identity-free, and the same
  authorization may reach surfaces where identities do appear.
- `REPORT-005` through `REPORT-007`: the credits earned export and its
  organizer/department scope split.
- `CREDIT-004`: frozen entries cannot be repriced.
- `CREDIT-005`: the basis behind every credited number is reportable.
- `ORG-010`: no department default credit policy; calculation stays with
  organizers.
- `CLIENT-005`, `CLIENT-006`: an unpermitted destination is absent from
  navigation, and the node refuses the request regardless of what was rendered.
- UI implementation contract section 12.4: `department.roster`,
  `department.deployments`, `department.credits`.
- Data/API spec section 10.12 `credit_ledger_entries`; section 10.14
  `deployments` and `current_deployment_assignments`.
- Accessibility checklist sections 8, 9, 14, and 18.

## Environment

- Development server environment with a migrated and seeded database
- Shared Meridian client (`apps/client`)
- Permission catalog as seeded; **no capability is added by M18.30** — every one
  of these three surfaces resolves through codes the catalog already registers
- For API checks, authenticated requests against
  `/api/events/{event}/departments/{department}/roster`,
  `/api/events/{event}/departments/{department}/deployments`,
  `/api/events/{event}/departments/{department}/credits`, and
  `/api/commands/create-deployment`, `/api/commands/update-deployment`,
  `/api/commands/archive-deployment`, and `/api/commands/restore-deployment`

## Personas

- Dana Departmentlead — Rangers department lead (`department.administer`)
- Ollie Operations — Rangers `department_operations`
  (`department.deployments.assign`) and nothing else
- Pria Planning — Rangers `department_planning`
  (`department.schedule.manage`) and nothing else
- Tam Teamlead — a designated lead of the Rangers Dirt team, holding no
  department capability
- Olive Organizer — organizer, whose standing is held in the Organizers
  Department
- Vera Staff — a Rangers member holding no roles at all

Each of the first four must hold their role through a **separate team**: a team
grant reaches everyone on the team it is attached to, so two of these on one
team is two people with both roles and a script that proves nothing.

## Setup data

- The seeded scenario, which supplies Rangers with teams and members
- At least two Rangers staff records carrying an emergency contact name and
  phone, and at least one carrying neither, so "none recorded" is a state on the
  page rather than a hypothesis
- At least one Rangers member on the Dirt team and one on a team Tam does not
  lead
- One Rangers member whose department membership status is not Active
- A Rangers event with a running shift, at least one deployment, and at least
  one staff member currently assigned to that deployment
- An event whose correction grace period has closed and whose credits have been
  calculated, with at least two credited Rangers staff, one credited through the
  organization default policy and one through a shift-specific rate
- At least one frozen hours record in Rangers carrying no credit entry, so the
  "not yet credited" line has something to report

## Steps

### The department staff list (`department.roster`)

1. Sign in as Dana Departmentlead and open the home surface.
2. Under **Department pages**, choose **Roster**, or navigate to
   `/events/{eventId}/departments/{departmentId}/roster`.
3. Confirm every active member of Rangers is listed with their name, handle,
   teams, phone, and email — including the members who hold roles, who are
   members too.
4. Confirm the member whose membership status is not Active is **on the list**
   with that status shown, rather than missing from it.
5. Confirm an **Emergency contact** column is present, that a member with one
   shows the name and the number, and that a member with none reads
   "None recorded".
6. Confirm the page states its own scope: that the list covers the whole
   department, and that emergency contacts are shown because you lead it.
7. Type part of a member's handle into **Search** and confirm the list narrows.
   Then disconnect the device from the network and search again; confirm it
   still narrows, because the roster is filtered where it is held.
8. Choose a team in the **Team** filter and confirm only that team's members
   remain.
9. Sign out, sign in as **Pria Planning**, and open the same address. Confirm
   the whole department is listed and that **no emergency contact column is
   rendered at all** — not a column of blanks. Confirm the page says emergency
   contacts belong to department leads for their own department.
10. `GET /api/events/{event}/departments/{department}/roster` as Pria and
    confirm the response bodies carry **no** `emergency_contact_name` and no
    `emergency_contact_phone` keys. A present key with a null value is a
    failure, not a pass.
11. Sign in as **Tam Teamlead** and open the same address. Confirm only the Dirt
    team's members are listed, that the Team filter offers only Dirt, and that
    no emergency contact column is rendered.
12. Sign in as **Olive Organizer** and open the same address. Confirm the node
    answers 403 and the page states the refusal: an organizer holds no
    department role here and reaches no roster through this surface
    (`VOL-011`).
13. Sign in as **Vera Staff** and confirm **Roster** is absent from navigation,
    then open the address directly and confirm the node answers 403.
14. As Dana, open the roster address naming a department Dana does not lead and
    confirm the node answers 403 rather than serving the list without the
    emergency contact columns.
15. Open the **Planning** surface as Pria and confirm it still shows aggregates
    only, with no staff name anywhere on it (`SLB-019`). Reading the roster does
    not put identities on the Planning Table.

### Deployment options (`department.deployments`)

16. Sign in as **Ollie Operations** and open **Deployments** under Department
    pages, or navigate to
    `/events/{eventId}/departments/{departmentId}/deployments`.
17. Confirm the existing deployments are listed, each with its description, its
    location details, and how many staff are standing at it now.
18. Add a deployment with a name, a description, and a location. Confirm it
    appears in the list and that the list was re-read from the node rather than
    patched locally.
19. Attempt to add a second deployment using the same name in different casing.
    Confirm the node refuses it and names the deployment that already holds the
    name.
20. Add a deployment for **another department** at the same event and confirm the
    same name is accepted there: uniqueness is per event and department.
21. Edit a deployment that has staff standing at it, changing its name. Confirm
    the change is accepted, and open the Operations Center to confirm those
    staff now read as deployed to the new name without anything else being
    touched.
22. Choose **Archive** on the deployment that has staff standing at it. Confirm
    the control was **offered** rather than disabled, that the node refuses it,
    and that the refusal names how many staff have to be moved.
23. Move those staff to another deployment from the Operations Center, return,
    and archive the deployment. Confirm it moves to the **Archived** section and
    is no longer offered in the Operations Center.
24. Choose **Restore** on an archived deployment and confirm it returns to the
    list and to the Operations Center.
25. Confirm the archived deployment's history survived: the staff who were
    assigned to it before archiving still read correctly wherever that
    assignment is shown.
26. Sign in as **Dana Departmentlead** and confirm Deployments is offered and
    the same edits are accepted: the surface answers to
    `department.deployments.assign` **or** `department.administer`.
27. Sign in as **Vera Staff** and confirm Deployments is absent from navigation,
    then `POST /api/commands/create-deployment` naming the Rangers department
    and confirm the node answers 403.
28. As Ollie, `POST /api/commands/update-deployment` naming a deployment of a
    department Ollie does not hold operations in, and confirm the node answers
    403 and the deployment is unchanged. The department is resolved from the
    deployment, not from anything the client sends.
29. On a node that does not hold event authority for the event (during its
    active event window, from central), attempt any of the four commands and
    confirm the node refuses the write rather than accepting it locally.

### Credit review (`department.credits`)

30. Sign in as **Dana Departmentlead** and open **Credits** under Department
    pages, or navigate to
    `/events/{eventId}/departments/{departmentId}/credits`.
31. Confirm the totals at the top state the credits, the hours, and how many
    staff were credited.
32. Confirm the **By staff member** table lists each credited member once with
    their shift count, hours, and credits, and that the per-member credits sum
    to the total above.
33. Confirm the **every entry** table carries, on each row, the hours, the rate,
    the credits, the policy name, and the policy source — and that hours
    multiplied by rate equals the credits shown, without opening anything else
    (`CREDIT-005`).
34. Confirm the member credited at a shift-specific rate reads `shift` as its
    policy source and the member credited at the organization default reads
    `organization`, so two identical rates are still told apart.
35. Confirm the page reports the frozen hours records carrying no credit entry,
    and the grace period close beside them.
36. Sign in as **Olive Organizer**, open the credit policy surface, and rename
    and re-rate the policy behind one of the credited entries.
37. Return to `department.credits` as Dana, reload, and confirm the entry still
    shows the **old** name, the **old** rate, and the **same** credits
    (`CREDIT-004`). Confirm the totals are unchanged.
38. Confirm the page offers the **Credits earned** export and states that its
    scope is this department within this event, and that no phone number,
    emergency contact, or date of birth is a column in it.
39. Run the export and confirm the file matches what the page shows.
40. Confirm the page offers **no** control that calculates, recalculates, or
    edits a credit: calculation is an organizer's (`ORG-010`).
41. As Dana, open the credits address naming a department Dana does not lead and
    confirm the node answers 403.
42. Sign in as **Olive Organizer** and open the credits address for Rangers.
    Confirm it opens — an organizer's export authority covers the event — and
    that the page reports the reader's authority as event-wide.
43. Sign in as **Vera Staff** and confirm Credits is absent from navigation, then
    open the address directly and confirm the node answers 403.

### Absences and accessibility

44. As Vera Staff, confirm none of **Roster**, **Deployments**, or **Credits**
    appears anywhere in navigation, on the home directory, or in the workflow
    menu (`CLIENT-005`).
45. Navigate every one of the three surfaces by keyboard alone. Confirm each
    control is reachable in a sensible order, that focus is visible on every
    one, and that the tables are announced with their column headers.
46. Confirm each page's error and status messages are announced — the refusals
    in particular, since a refusal a screen reader does not announce is a form
    that silently does nothing.
47. Resize to a narrow viewport and confirm each table scrolls inside its own
    band rather than pushing the page sideways.
48. Switch to the dark theme and confirm every state on all three pages stays
    legible.

## Expected results

- The roster lists the whole department for `department.administer` and
  `department.schedule.manage` holders, only the led teams for a designated team
  lead, and refuses everybody else with 403 rather than an empty list.
- Emergency contact fields are present in the response only for
  `department.administer` holders over the department being read, and are absent
  keys — not null values — for everybody else.
- An organizer reaches no roster through this surface at all.
- Non-active memberships appear on the roster carrying their status.
- Roster search and team filtering work with the device disconnected once the
  page has loaded.
- The Planning Table remains identity-free regardless of what its holder can
  read elsewhere.
- Deployments can be created, renamed, re-described, archived, and restored by
  `department.deployments.assign` or `department.administer` holders, and by
  nobody else.
- Deployment names are unique per event and department, case-insensitively, and
  the refusal names the existing one.
- A deployment holding staff cannot be archived; the refusal states the count,
  and the control is offered rather than disabled.
- Renaming a deployment moves every current assignment with it; archiving one
  destroys no history.
- Deployment writes are refused on a node that does not hold event authority.
- Credit review shows per-staff totals and per-entry arithmetic that a reader can
  re-check on the row.
- A renamed or re-rated policy leaves every frozen entry, and every total,
  unchanged.
- Hours worked and not yet credited are reported rather than silently absent.
- The credits export is offered from the page, scoped to the department, and the
  page offers no way to calculate or change a credit.
- Every one of the three surfaces is absent from navigation for a reader holding
  nothing, and the node refuses the address regardless.

## Evidence to capture

- Screenshots of the roster as a department lead (emergency contact column
  present) and as a planning holder (column absent), side by side
- The raw JSON of one roster row read as a planning holder, showing the two keys
  are missing rather than null
- A screenshot of the archive refusal naming the number of staff standing at the
  deployment
- Screenshots of a deployment's name before and after a rename, with the
  Operations Center showing the same staff at the new name
- Screenshots of one credit entry before and after its policy is renamed and
  re-rated, showing the entry unchanged
- The generated credits earned file beside the page it was run from
- The 403 responses for each unauthorized address attempted
- Keyboard focus order and dark-theme captures for all three surfaces

## Failure notes

- Record the persona, the surface, the address, and the exact response body for
  any refusal that did not arrive or arrived with the wrong status.
- An emergency contact field present with a null value is a **failure**, not a
  cosmetic difference: the whole point of omitting the key is that a blank means
  "none recorded" rather than "withheld from you". Record the payload.
- If a credit entry moved when its policy was re-rated, stop and record the
  entry id, its `calculation_basis`, and the policy's before and after values —
  that is a `CREDIT-004` break and it is not repairable by re-running anything.
- If a deployment holding staff was archived, record the deployment id and every
  `current_deployment_assignments` row still pointing at it.
- Note any surface that rendered a control the node then refused, and any
  control the node would have accepted that the surface did not offer
  (`CLIENT-005`, `CLIENT-006`).
