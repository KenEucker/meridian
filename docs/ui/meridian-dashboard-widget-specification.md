# Meridian Dashboard Widget Specification

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define how Meridian dashboard widgets should be selected, structured, prioritized, and reviewed.

---

## 1. Purpose

This specification defines Meridian dashboard widget rules across volunteer, department lead, shift lead, organizer, IC lead, kiosk, and mobile contexts.

Dashboard widgets should help users understand what needs attention, what can be acted on, and what is currently okay.

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

---

## 6. Role-Based Widget Model

Widgets are fixed by role for MVP.

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
- active incidents;
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

---

## 17. Open Questions

Future versions should define:

- exact MVP widget inventory;
- per-role widget ordering;
- attention-score calculation;
- widget data freshness rules;
- chart component contract;
- mobile feed grouping rules;
- kiosk dashboard widget set.

