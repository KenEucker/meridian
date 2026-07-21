import { describe, expect, it } from "vitest";

import {
  type IncidentTimelineEntry,
  visibleIncidentTimelineEntries,
} from "@/ims/incidentReadModel";

describe("visibleIncidentTimelineEntries", () => {
  it("keeps compact incident history focused on creation, latest open/close, notes, and Field Reports", () => {
    const entries = [
      timelineEntry({
        id: "opened",
        entryType: "incident_opened",
        body: "Incident INC-2027-000001 opened.",
      }),
      timelineEntry({
        id: "older-close",
        entryType: "incident_field_updated",
        body: "Changed status: Closed",
        newValue: { status: "closed" },
      }),
      timelineEntry({
        id: "routine-priority",
        entryType: "incident_field_updated",
        body: "Changed priority: Serious",
        newValue: { priorityLabel: "Serious" },
      }),
      timelineEntry({
        id: "note",
        entryType: "operational_note",
        body: "Responder monitoring scene.",
      }),
      timelineEntry({
        id: "stricken-report",
        entryType: "field_report_linked",
        body: "Field Report: Superseded report",
        strickenAt: "2027-07-04T21:10:00.000Z",
      }),
      timelineEntry({
        id: "report",
        entryType: "field_report_linked",
        body: "Field Report: Active report",
      }),
      timelineEntry({
        id: "latest-reopen",
        entryType: "incident_field_updated",
        body: "Changed status: Open",
        newValue: { status: "open" },
      }),
      timelineEntry({
        id: "attachment-stricken",
        entryType: "incident_attachment_stricken",
        body: "Attachment stricken from incident.",
      }),
    ];

    expect(
      visibleIncidentTimelineEntries(entries, false).map((entry) => entry.id),
    ).toEqual(["opened", "note", "report", "latest-reopen"]);
    expect(visibleIncidentTimelineEntries(entries, true)).toEqual(entries);
  });
});

function timelineEntry(
  overrides: Partial<IncidentTimelineEntry>,
): IncidentTimelineEntry {
  return {
    id: "entry",
    incidentId: "incident",
    actorName: "IC Operator",
    entryType: "operational_note",
    body: null,
    createdAt: "2027-07-04T21:00:00.000Z",
    ...overrides,
  };
}
