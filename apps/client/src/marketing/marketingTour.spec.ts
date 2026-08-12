// The feature tour catalogue and its committed screenshot assets (M20.2,
// M20.3; PUBLIC-007, PUBLIC-008).

import { describe, expect, it } from "vitest";

import { MARKETING_FEATURE_TOUR } from "@/marketing/marketingTour";

/**
 * Every screenshot asset committed under the client's public directory, as
 * the public paths they are served from. `import.meta.glob` scans the real
 * filesystem when the test is transformed, so this is the repository's own
 * answer to "which assets exist" — no server and no fs shim involved.
 */
const committedScreenshots = Object.keys(
  import.meta.glob("../../public/assets/marketing/northwood/*.webp"),
).map((path) => path.replace("../../public", ""));

describe("the marketing feature tour catalogue", () => {
  it("has a stable, unique, anchor-safe id per feature area", () => {
    const ids = MARKETING_FEATURE_TOUR.map((feature) => feature.id);

    expect(new Set(ids).size).toBe(ids.length);

    for (const id of ids) {
      expect(id).toMatch(/^[a-z][a-z-]*$/);
    }
  });

  it("says what each feature does, and what each screenshot shows", () => {
    for (const feature of MARKETING_FEATURE_TOUR) {
      expect(feature.title.trim()).not.toBe("");
      expect(feature.description.trim()).not.toBe("");
      // The alt text is a sentence about the image, not a repeat of the title
      // — a reader who cannot see the screenshot gets what it shows, not a
      // second copy of the heading.
      expect(feature.screenshotAlt.trim()).not.toBe("");
      expect(feature.screenshotAlt).not.toBe(feature.title);
    }
  });

  /**
   * PUBLIC-008: the screenshots are static assets captured from the seeded
   * Northwood scenario and committed to the repository. A tour entry whose
   * asset is missing would render a broken image on the one page that exists
   * to make a first impression, so the asset's presence is asserted here
   * rather than discovered in production.
   */
  it("commits a Northwood screenshot asset for every feature area", () => {
    for (const feature of MARKETING_FEATURE_TOUR) {
      expect(feature.screenshot).toBe(
        `/assets/marketing/northwood/${feature.id}.webp`,
      );

      expect(
        committedScreenshots,
        `missing ${feature.screenshot} — capture it per docs/process/marketing-screenshot-recapture.md`,
      ).toContain(feature.screenshot);
    }
  });
});
