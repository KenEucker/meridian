# Meridian Dashboard Widget Specification

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define how Meridian dashboard widgets should be selected, structured, prioritized, and reviewed.

---

## 1. Purpose

This specification defines Meridian dashboard widget rules across volunteer, department lead, shift lead, organizer, IC lead, kiosk, and mobile contexts.

Dashboard widgets should help users understand what needs attention, what can be acted on, and what is currently okay.

When this document conflicts with the canonical Meridian UI Operating Guide, the parent guide governs. Deterministic MVP widget IDs, permissions, status labels, surface modes, and route destinations are defined in `docs/ui/meridian-ui-implementation-contract.md`.

---

## 2. Widget Principles

Widgets should be:

- role-aware;
- event-aware;
- action-oriented;
- quiet when nothing needs attention;
- concise;
- accessible;
- useful on mobile and touch devices;
- honest about offline and sync limits.

Widgets must not exist only because data is available.

Organizer role alone does not grant access to IMS incidents or restricted IMS surfaces. Incident records, incident dashboards, restricted Field Report review surfaces, incident counts, high-priority incidents, on-scene incidents, monitoring incidents, and IMS-specific alerts require appropriate IC permissions for the event's configured IC department.

---

## 3. Widget Types

Allowed MVP widget types:

- metric card;
- action card;
- alert;
- list;
- chart;
- quiet-state reassurance.

IMS screens should favor lists, timelines, and tables over charts or decorative widgets.

---

## 4. Required Widget Anatomy

Each widget should define:

- title;
- role and permission visibility;
- organization and event scope;
- department scope when applicable;
- attention level;
- data freshness;
- empty or quiet state;
- loading state;
- error state;
- offline or sync behavior;
- primary action when available;
- destination when selected.

Widgets that cannot answer these items should not ship.

---

## 5. Attention Scale

Meridian dashboard widgets should use a standard attention scale:

- Routine: useful information, no action required;
- Attention: user should review soon;
- Warning: operational issue needs action;
- Critical: urgent operational issue;
- Restricted: security-sensitive or high-impact state.

Attention must be communicated through label, structure, and iconography, not color alone.

Dashboard attention describes how urgently the UI should draw attention to a condition. IMS priority describes the operational seriousness of an incident. Incident state describes workflow state. These three concepts must remain visually and textually distinct. Provisional MVP IMS priority labels are defined in the implementation contract and are product-reviewable.

---

## 6. Role-Based Widget Model

Widgets are fixed by role for MVP.

The fixed MVP inventory is summarized below. The implementation contract remains the source for exact widget IDs and route destinations.

| Widget group | Widget IDs | Role visibility | Scope | Data source/model | Attention behavior | Quiet state | Offline/sync behavior | Primary destination |
|---|---|---|---|---|---|---|---|---|
| Volunteer | `volunteer.current_shift`, `volunteer.upcoming_shifts`, `volunteer.assigned_departments`, `volunteer.shift_alerts`, `volunteer.quiet_state` | authenticated volunteer | user/event/org | shifts, assignments, department memberships, alerts | shift alerts and current shifts outrank routine membership | No current shift; no upcoming shifts; nothing needs action | show stale/queued state only where it affects current work | my shifts, departments, alert source |
| Department Lead | `dept.coverage_issues`, `dept.shift_readiness`, `dept.checkin_status`, `dept.unresolved_reports`, `dept.equipment_returns` | department lead or permitted shift lead | department/event | shifts, attendance, department reports, equipment | coverage/check-in issues can escalate to Warning | All scheduled shifts covered; no reports awaiting review | local/central status shown when department data may be stale | shifts, shift board, reports, equipment |
| Shift Lead | `shift.current_roster`, `shift.late_missing`, `shift.deployment_needs`, `shift.equipment_status` | shift lead | shift/department/event | roster, attendance operations, deployments, equipment | late/missing and deployment needs are attention items | No late or missing volunteers; equipment accounted for | attendance writes may be queued; show only to roles needing trust | shift board |
| Organizer | `org.event_readiness`, `org.cross_dept_coverage`, `org.application_review`, `org.planning_tasks`, `org.operations_window` | organizer | organization/event | readiness, coverage summaries, applications, planning tasks | readiness gaps and applications awaiting review draw attention | Event readiness looks okay; no applications awaiting review | do not imply central truth when event data is stale | readiness, coverage, applications, event |
| IC roles | `ic.active_incidents`, `ic.serious_incidents`, `ic.on_scene`, `ic.monitoring`, `ic.unresolved_field_reports` | IC viewer/operator/lead only | event/IC department | incidents, incident state, IMS priority, Field Reports | serious/high-priority and on-scene items can be Critical | No active incidents; no Field Reports awaiting IC review | incidents require server connection for creation; Field Reports may be queued | IMS dashboard, incidents, Field Reports |
| Kiosk | `kiosk.current_tasks`, `kiosk.staff_checkin`, `kiosk.equipment_returns`, `kiosk.node_status`, `kiosk.switch_user` | trusted workstation plus user permissions | kiosk/event/department | operational tasks, attendance, equipment, node/sync state | current tasks and node/sync failures draw attention where actionable | No current kiosk tasks; local node reachable | show local node, central, queued, conflict, failed states where relevant | kiosk home, check-in, equipment, node status |

### 6.1 Volunteer

Volunteer widgets may include:

- assigned departments;
- upcoming or current shifts;
- shift-related alerts;
- department-membership alerts;
- quiet state when no current action is needed.

### 6.2 Department Lead

Department lead widgets should appear before the general volunteer structure.

They may include:

- department coverage issues;
- check-in status;
- shift readiness;
- department alerts;
- unresolved department reports.

### 6.3 Shift Lead

Shift lead widgets should surface through relevant department, shift, and operational contexts.

They may include:

- current shift roster;
- late or missing volunteers;
- deployment needs;
- check-in corrections;
- equipment return status.

### 6.4 Organizer

Organizer widgets should operate at organization or event level.

They may include:

- event readiness;
- cross-department coverage;
- upcoming planning tasks;
- reports requiring review;
- operations-window status.

Organizer widgets must not surface IMS data unless the same user also has IC permissions.

### 6.5 IC Lead

IC lead widgets should include event operations and department-relevant attention items.

They may include:

- active incident counts;
- high-importance incidents;
- monitoring incidents;
- on-scene incidents;
- unresolved field reports;
- restricted or serious state alerts.

---

## 7. Event Window Behavior

When the event is inside its operations window, widgets should prioritize:

- current shifts;
- check-in status;
- active incidents only for IC roles;
- coverage issues;
- urgent alerts;
- deployments;
- equipment and operational readiness.

When the event is outside its operations window, widgets should emphasize:

- planning;
- review;
- reports;
- upcoming responsibilities;
- configuration or readiness gaps.

---

## 8. Mobile Priority Feed

On mobile, dashboard widgets should collapse into a single priority feed.

The feed should:

- order by attention level and role relevance;
- preserve source context;
- group related low-priority items;
- keep quiet states visible when reassuring;
- avoid dense multi-column layouts.

The priority feed is active when `surfaceMode` is `mobile` and may also be used in `touch` or `kiosk` where a feed is more usable than a grid. Related Routine items may be grouped; Warning, Critical, and Restricted items should remain individually visible.

---

## 9. Kiosk Dashboard Widgets

Kiosk widgets should be larger, simpler, and action-oriented.

Kiosk widgets should:

- hide admin complexity by default;
- show organization and event context;
- respect trusted workstation state;
- support touch;
- avoid tiny secondary controls;
- expose only kiosk-appropriate command palette destinations.

Kiosk widgets must distinguish trusted workstation state from individual user authority.

---

## 10. Metric Widgets

Metric widgets must answer why the number matters.

Required content:

- concise label;
- value;
- scope;
- attention state when applicable;
- timestamp or freshness when data may be stale;
- action or drill-down if the number requires attention.

Avoid decorative metrics with no operational consequence.

---

## 11. Alert Widgets

Alert widgets should be reserved for attention-worthy states.

Alerts should include:

- concise title;
- affected scope;
- attention level;
- recommended action or destination;
- canonical status labels where applicable.

Alerts must not rely only on color.

---

## 12. List Widgets

List widgets should show a small set of relevant items.

Lists should:

- cap visible items;
- provide a clear route to the full list;
- show status and priority where relevant;
- avoid duplicating full table behavior;
- remain keyboard accessible.

---

## 13. Chart Widgets

Charts may be used where they clarify attention or operational readiness.

Preferred chart uses:

- staffing heat maps;
- simple bar charts;
- simple line charts;
- status summaries.

Avoid:

- 3D charts;
- decorative charts;
- pie charts where a table or status summary is clearer;
- charts on IMS screens unless they directly improve operational readiness.

---

## 14. Quiet States

Quiet states are desirable.

Examples:

- No active incidents.
- All scheduled shifts covered.
- No reports awaiting review.
- No sync issues in this event.

Quiet states should be calm and concise. They should reassure rather than celebrate.

---

## 15. Offline and Sync Behavior

Widgets affected by connectivity should show data freshness or sync state where it matters.

Required behavior:

- do not imply current status when data may be stale;
- show unavailable actions honestly;
- avoid interruptive sync failure messages;
- expose queued actions only where useful.

Allowed connectivity labels should match the implementation contract: Online, Offline but usable, Local node reachable, Central unreachable, Queued, Sync conflict, and Sync failed.

---

## 16. Widget Review Checklist

Review each widget for:

- clear user purpose;
- role and permission rules;
- event and department scope;
- attention level;
- canonical status language;
- empty, loading, error, and quiet states;
- mobile priority feed behavior;
- touch and kiosk behavior where relevant;
- accessibility;
- light and dark mode;
- offline and sync behavior.
- `surfaceMode` behavior.

---

## 17. Open Questions

Future versions should define:

- per-role widget ordering;
- attention-score calculation;
- exact widget data freshness intervals;
- chart component contract;
- final product-reviewed IMS priority labels.
