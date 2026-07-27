/**
 * Event Info section vocabulary and assembly order (M11.20).
 *
 * Mirrors `App\Domain\Documents\EventInfoSection` on the server. Event Info is
 * assembled from documents a maintainer explicitly assigned to a section, never
 * guessed from titles or slugs: a screen that guesses which document means
 * "directions" eventually guesses wrong in front of someone driving to a gate
 * at night.
 */
export const EVENT_INFO_SECTIONS = [
  "directions",
  "arrival",
  "packing",
  "food",
  "housing",
  "requirements",
] as const;

export type EventInfoSection = (typeof EVENT_INFO_SECTIONS)[number];

const SECTION_LABELS: Readonly<Record<EventInfoSection, string>> = {
  directions: "How to get to the event",
  arrival: "Arrival requirements",
  packing: "What to bring",
  food: "Food",
  housing: "Housing",
  requirements: "Event requirements",
};

/**
 * Shown when a section has no visible published document, so the empty state
 * names the gap instead of standing in for the answer.
 */
const SECTION_EMPTY_DESCRIPTIONS: Readonly<Record<EventInfoSection, string>> = {
  directions:
    "No published document covers travel, gate access, or arrival checkpoints for this event yet.",
  arrival:
    "No published document covers arrival requirements, credentials, or check-in for this event yet.",
  packing:
    "No published document covers what staff should bring to this event yet.",
  food: "No published document covers meals or food availability for this event yet.",
  housing:
    "No published document covers housing, camping, or shelter for this event yet.",
  requirements:
    "No published document covers what this event requires of staff yet.",
};

export function eventInfoSectionLabel(section: EventInfoSection): string {
  return SECTION_LABELS[section];
}

export function eventInfoSectionEmptyDescription(
  section: EventInfoSection,
): string {
  return SECTION_EMPTY_DESCRIPTIONS[section];
}

export function isEventInfoSection(
  value: unknown,
): value is EventInfoSection {
  return (
    typeof value === "string" &&
    (EVENT_INFO_SECTIONS as readonly string[]).includes(value)
  );
}
