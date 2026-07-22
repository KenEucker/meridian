# Meridian Briefing Surface Specification

Version: Draft 2  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define UI expectations for Notes and The Briefing hub / related Command communication surfaces.

---

## 1. Purpose

This specification defines surface rules for standalone Notes and The Briefing hub: an event-scoped Incident Command surface that shares Command-added Notes, After Action Reports, Directions, Action Plans, and Notices across departments.

When this document conflicts with the Meridian UI Operating Guide, the parent guide governs. Deterministic Alpha 1 routes, screen IDs, permission predicates, and offline rules are defined in `docs/ui/meridian-ui-implementation-contract.md`.

---

## 2. Design Posture

The Briefing should feel operational and Command-owned, but less restricted than IMS.

It should communicate:

- Notes as a private author+Command pool until Command adds them;
- clear authorship credit when Notes are referenced or linked into The Briefing;
- lead submission privacy for Submission AARs until Final publication;
- urgency without panic for Notices;
- restraint for Action Plan banners (allowlisted surfaces only).

Avoid treating Notes as a public wiki, chat feed, or IMS substitute.

---

## 3. Required Surface Types

- Notes list (author/Command scoped)
- Note create
- Note detail
- Command add-to-Briefing (reference or link)
- Briefing hub (shows Command-added Notes + other sections)
- AAR submission list/detail/edit (post–Alpha 1)
- Final AAR detail (post–Alpha 1)
- Direction detail/edit (post–Alpha 1)
- Action Plan detail/edit (post–Alpha 1)
- Notice list / dismissible alert chrome (post–Alpha 1)
- empty-state shells for deferred types (Alpha 1)

---

## 4. Briefing Hub

The hub is the primary event-staff entry for Command-published Briefing content.

Required regions:

1. Context (organization, event, role-relevant actions)
2. Active Notices strip (post–Alpha 1; Alpha 1 may show placeholder)
3. Notes added to The Briefing (live in Alpha 1 once Command adds them)
4. Directions section (shell in Alpha 1)
5. Action Plan section (shell in Alpha 1)
6. After Action Reports section (shell in Alpha 1)
7. Notices archive/list (shell in Alpha 1)

Hub empty shells must state that the type is specified for post–Alpha 1 and is not yet available, without implying a broken feature.

Ordinary event staff see **only Notes Command has added** with `event_staff` audience (plus any other Briefing items they are permitted to see). Department-leads-only inclusions are hidden from ordinary staff and from team leads who are not also department leads/Command/organizers.

---

## 5. Notes

### Create

- Online-only in Alpha 1
- Title optional; body required Markdown
- Clear copy that Notes cannot be edited after create
- Authors: department leads, team leads, IC operators/leads

### Author / Command list and detail

- Author sees own Notes
- Command sees Notes from all authors for the event
- Show author, created time, Markdown body
- No edit/append controls
- Ordinary event staff do not see unadded Notes

### Command add to Briefing (Alpha 1)

Command chooses a Note and an inclusion mode:

**Reference**

- Command writes a summary
- Hub shows the summary with credit to the original author
- Control to view the original Note

**Link**

- Hub shows the Note body verbatim
- Credit to the original author
- Reads as Command communication attributed to that individual

**Audience**

- Command chooses **event staff** (default) or **department leads only**
- Department-leads-only: visible to department leads, Command, and organizers; not to team leads (unless they also hold one of those roles)

### AAR inclusion (post–Alpha 1)

Same reference/link modes as Briefing add.

---

## 6. After Action Reports (post–Alpha 1)

- Fixed ICS tabs/sections: Command, Operations, Logistics, Planning, Admin
- Submission AARs scoped to department or team
- Submit/resubmit with window countdown through event end + 30 days
- IC Final compile/publish; day-45 auto-assemble messaging when frozen
- Peer submissions hidden from other leads until Final is published

---

## 7. Directions (post–Alpha 1)

- Markdown body with entity link chips/pickers
- Optional department/team targeting
- Optional audience: event staff or department leads only
- No banner chrome

---

## 8. Action Plan (post–Alpha 1)

- Sections with optional dept/team targeting
- Audience mark on whole plan and/or sections (event staff or department leads only)
- Banner toggle limited to allowlisted screen IDs; department-leads-only banners only for permitted viewers
- Publish may confirm Notice spawn

### Banners

Default Alpha design allowlist (may be refined later):

- `staff.dashboard`
- `staff.me`
- `department.overview`
- `department.operations`
- `event.info`
- `briefing.hub`

---

## 9. Notices (post–Alpha 1)

- Hub list plus dismissible per-user alert
- Optional audience: event staff or department leads only
- Severity styling restrained (`info`, `process`, `emergency`)
- Expired notices leave alert chrome but remain historically listable where permitted

---

## 10. Permissions and Fail-Closed UI

Denied create/edit/add actions fail closed with an explainable reason when possible.

IMS access is not granted by opening The Briefing or Notes.

---

## 11. Offline Behavior

Alpha 1:

- Author/Command may read synced Notes they are allowed to see
- Approved event staff may read synced Briefing inclusions
- Note create and add-to-Briefing require connection

Post–Alpha 1 offline rules follow technical spec section 21B.8.

---

## 12. Accessibility

- Banner and Notice chrome must be keyboard reachable and dismissible without pointer-only gestures
- Shell placeholders must not be announced as errors
- Reference inclusions must expose a clear path to view the original Note
- Markdown rendering follows existing policy/procedure sanitizer rules
