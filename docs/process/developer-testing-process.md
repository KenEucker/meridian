# Meridian Developer Testing Process

**Project:** Meridian
**Document type:** Developer testing guide
**Source documents:**

- `docs/process/meridian-development-process.md` (section 13.3 defines the seed)
- `apps/server/database/seeders/`
- `docs/qa/` (the human QA scripts these logins are used to run)

---

## 1. Purpose

This document is the answer to "who do I sign in as to test this?"

`php artisan migrate:fresh --seed` builds a complete operational scenario for
one organization across two events. Every login below exists in that scenario,
every one of them is there for a reason, and this document states the reason.
It is the companion to development process section 13.3, which describes the
data; this describes the people and what each of them is for.

Nothing here is a real person. The organization, the events, the staff, and the
images attached to them are all fictional and generated.

---

## 2. Getting a seeded database

```bash
corepack pnpm run db:up
corepack pnpm run server:migrate:seed
```

`migrate:fresh --seed` drops everything and rebuilds. The schedule is anchored
to the moment you run it, so shifts line up with the current day and hour: one
shift ended six hours ago, one is running now, one starts this afternoon. Re-run
it whenever the scenario has drifted out from under you — for example the next
morning, when yesterday's "running now" shift has finished.

Re-running `db:seed` without `migrate:fresh` is safe. The operational seeders
are idempotent on natural keys, so a second run recognizes what it already made
rather than stacking a second copy of the schedule on top.

---

## 3. The password

Every seeded account uses the same development password:

```text
password
```

It is applied by `DevelopmentScenarioCatalog::DEFAULT_PASSWORD` and is
development-only. The server refuses to boot with default secrets outside a
development environment, which is the check that keeps this from ever being a
production credential.

Signing in through the client is a magic-link flow rather than a password form.
Request a code for the address below, then read it out of the log:

```bash
corepack pnpm run server:mailtail
```

---

## 4. The two events

| Event | Slug | Window | What you use it for |
|---|---|---|---|
| Emberfall 2026 | `emberfall-2026` | started two days ago, ends in three | Everything operational: the desk, attendance, hours, equipment, deployments, incidents |
| Emberfall Decompression 2026 | `emberfall-decompression-2026` | six weeks out | Shift signup, and anything that needs an event nobody has started working |

**The running event is inside its active window, so organization governance is
frozen.** Branding, policies, procedures, and fragments refuse edits
organization-wide until the window closes — that is the product working
correctly, and it is worth meeting once on purpose. To administer governance
against a seeded database, close the window and the organization thaws:

```bash
php apps/server/artisan tinker --execute='App\Models\Event::query()->update(["active_event_window_starts_at" => null, "active_event_window_ends_at" => null]);'
```

Re-seeding puts it back.

---

## 5. The logins

All addresses are `@northwood-collective.test`. "Roles" are the effective
permission roles the account resolves; an asterisk marks an event-scoped grant.

### 5.1 Ordinary staff — no authority at all

These four hold no permission roles whatsoever. That is the point of them: they
are what a permission boundary is tested *against*, and each is parked in a
different operational state so the Logistics Desk has every branch on screen at
once.

| Login | State right now | Use it to test |
|---|---|---|
| `vera.staff` | Checked in on Day Patrol, holding a vest | Check-out; off-site refused while checked in; the staff self-service surfaces |
| `nora.newstaff` | On-site, on no running shift | **Unscheduled shift addition** — the one case SLB-008 exists for. Also holds a future signup and the single seat on Decompression Teardown |
| `felix.fieldhand` | Marked no-show on Morning Patrol; on the Day Patrol roster, not arrived | Check-in; mark no-show; a shift signup **refused for a missing training** (he has no Radio Training) |
| `quinn.quartermaster` | On-site, holding a radio written off as missing | The SLB-018 exception: outstanding equipment that no longer blocks going off-site. Also a signup **refused for a lapsed waiver** |
| `mira.commandstaff` | Checked in on Command Day Watch | The eligible-team refusal — a Dirt member cannot be added to her Command shift |

### 5.2 Department authority

| Login | Roles | Use it to test |
|---|---|---|
| `sam.shiftlead` | `shift_lead`, `department_logistics`, `department_operations`, `department_administration`, `department_planning` | The whole Logistics Desk, the Operations Center, the Planning Table, department self-administration. **The default choice for operational testing.** Also checked in and holding a shift-scoped radio, so his own workspace shows the equipment off-site block |
| `tess.teamlead` | `shift_lead` (Dirt) | **The narrow team-lead path** (M18.16): creating and editing Dirt's shifts and assigning their credit policy, with only Dirt offered as the eligible team. Sam cannot prove this — his `department_administration` opens every team's shifts before `shift_lead` gets a say. Also the refusal side: department administration, other teams' shifts, and organizer surfaces all stay closed to her |
| `dana.departmentlead` | `department_lead` | Department Overview, and what a lead sees that a shift lead does not. The actor behind most seeded history, so audit trails name her |
| `gabe.gatekeeper` | `department_lead`, `department_logistics` (Gate) | That department scope is real — Gate's desk holds Gate's staff, shifts, and scanners, and nothing of Rangers' |
| `dex.dpw` | `department_lead`, `department_logistics` (DPW) | The department switcher, and a third department that is not a copy of the first two |

Sam and Dana each sit on two teams: an authority team that carries their grants
and the Dirt crew team so they can still be rostered onto Dirt's shifts. That
split is deliberate — a team grant applies to every member of the team, so
authority parked on the crew team would make Vera a department lead.

### 5.3 Organizer and Incident Command

| Login | Roles | Use it to test |
|---|---|---|
| `olive.organizer` | `organizer` | Organizer department and staff administration, the document library, branding, credential export, the application review queue. Authored the seeded documents and logos |
| `ingrid.iclead` | `ic_lead`* | The IMS: incident detail, closing, linking, the Field Report review list. Owns the seeded "Open and serious" list preset, which nobody else can see |
| `omar.icoperator` | `ic_operator`* | Creating and updating incidents, appending notes. Wrote the seeded notes including the struck one |
| `ivy.icviewer` | `ic_viewer`* | Read-only IMS: everything visible, nothing editable |

### 5.4 Status and edge-case personas

| Login | State | Use it to test |
|---|---|---|
| `gwen.godmode` | No department | God Mode / Orchid console access, once granted |
| `debbie.dns` | Do Not Staff | That a Do Not Staff person is refused assignment, signup, and credentials everywhere |
| `pat.prospective` | Prospective; undecided application on the upcoming event; **no avatar** | Application approval, and the lettermark fallback where no profile picture exists |
| `ira.ineligible` | Department-ineligible in Gate; **no avatar** | Department eligibility refusals, distinct from organization status refusals |

### 5.5 Applicants with no login

Four applications sit on the upcoming event's review queue with no user account
behind them, which is what an application from outside looks like:

| Applicant | Status |
|---|---|
| Wren Waiting | Submitted, undecided |
| Ada Approved | Approved |
| Rory Rejected | Rejected |
| Del Deferred | Deferred |

Pat Prospective's application is the fifth, and is also undecided — so there is
always something in the queue to approve.

### 5.6 God Mode / Orchid

The Orchid console user is **not** part of the scenario seed. Create it
separately:

```bash
corepack pnpm run server:user
```

That creates `admin@example.com` with the password `password`.

---

## 6. Choosing a login

| If you are testing… | Sign in as |
|---|---|
| Check-in, check-out, no-show, hours correction | `sam.shiftlead` |
| Whether an ordinary staff member is correctly refused | `vera.staff` |
| Adding somebody to a *running* shift (unscheduled addition) | `sam.shiftlead`, acting on `nora.newstaff` |
| Rostering somebody onto a shift ahead of time | `dana.departmentlead` — assignment is lead authority (SHIFT-015), which Sam does not hold |
| Editing one team's shifts and assigning their credit policy, as a team lead | `tess.teamlead` — the Dirt-only path; "Standard Hour" is the organization default and "Overnight Multiplier" is the rate worth choosing over it |
| A refusal with a reason on it | `sam.shiftlead`, acting on `felix.fieldhand` or `quinn.quartermaster` |
| Department Overview and planning | `dana.departmentlead` |
| Shift signup and its four refusals | `vera.staff` or `felix.fieldhand`, on the upcoming event |
| Documents, branding, organizer administration | `olive.organizer` (close the active window first) |
| Incidents, Field Report review | `ingrid.iclead` or `omar.icoperator` |
| Department scoping | `gabe.gatekeeper` vs `sam.shiftlead` |
| Empty states and fallbacks | `pat.prospective`, `ira.ineligible` |

---

## 7. What the scenario already contains

Development process section 13.3 has the full inventory. The short version, so
you know what you do not have to create by hand:

- **Shifts** on the running event that already ended, are running, and have not
  started, plus a cancelled one and a training-gated one.
- **Attendance** in every state, with an hours record on both sides of the
  correction window — one open, one frozen — and one correction already applied
  so the audit trail has a before and an after.
- **Equipment** available, checked out against a shift, checked out against the
  event, written off while still out, damaged, and archived.
- **Trainings and waivers** with completions, a prerequisite chain, a pending
  signup, an archived training, and one lapsed waiver.
- **Credit policies**: "Standard Hour" (1.000) as the organization default and
  "Overnight Multiplier" (1.5) carried by Overnight Patrol, so the SHIFT-010
  override and the CREDIT-003 fallback both resolve.
- **Documents** in every state at two scopes, a shared fragment, filled Event
  Info sections, and a half-satisfied acknowledgment requirement.
- **Six incidents** spread across status and priority, three Field Reports, a
  struck note, a link between two incidents, and a saved list preset.
- **Images**: staff avatars, organization/department/team/event logos, and Field
  Report photos, all generated at seed time.

Some things are deliberately absent: two staff without avatars, one Field Report
without a photo, one shift under capacity, one roster entry with no check-out.
Empty states are rendering paths too, and a scenario where everything is
populated never shows them.

---

## 8. When the seed is wrong

`OperationalScenarioSeedTest` guards the *shape* of the scenario rather than row
counts — that the schedule straddles the seeding moment, that every attendance
state has somebody in it, that hours exist on both sides of the correction
window. Run it when you change a seeder:

```bash
cd apps/server && php artisan test --filter=OperationalScenarioSeedTest
```

It exists because the failure mode is quiet. A seed that drifts still seeds
without error and still looks plausible in the database; it just leaves whoever
opens the Logistics Desk with nothing to press, and they find out by wasting an
afternoon on it.

---

## 9. Related documents

- `docs/process/meridian-development-process.md` — section 13.3, the seed data
  strategy this implements
- `docs/qa/README.md` — the human QA scripts, which use these personas by name
- `docs/process/traceability-matrix.md` — which requirement each QA script covers
