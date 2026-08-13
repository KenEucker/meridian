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

const allPerspectives = MARKETING_FEATURE_TOUR.flatMap((feature) =>
  feature.perspectives.map((perspective) => ({ feature, perspective })),
);

describe("the marketing feature tour catalogue", () => {
  it("has a stable, unique, anchor-safe id per feature area", () => {
    const ids = MARKETING_FEATURE_TOUR.map((feature) => feature.id);

    expect(new Set(ids).size).toBe(ids.length);

    for (const id of ids) {
      expect(id).toMatch(/^[a-z][a-z-]*$/);
    }
  });

  /**
   * One or two sides per feature, each a real surface: the lead/organizer
   * side first by convention, and a second only where the product has one.
   * Two sides never share a screenshot — a switch that swaps nothing is a
   * control with no consequence.
   */
  it("carries labelled perspectives with distinct screenshots", () => {
    for (const feature of MARKETING_FEATURE_TOUR) {
      expect(feature.perspectives.length).toBeGreaterThanOrEqual(1);
      expect(feature.perspectives.length).toBeLessThanOrEqual(2);

      const perspectiveIds = feature.perspectives.map((p) => p.id);
      expect(new Set(perspectiveIds).size).toBe(perspectiveIds.length);

      for (const perspective of feature.perspectives) {
        expect(perspective.label.trim()).not.toBe("");
        expect(perspective.microFeatures.length).toBeGreaterThanOrEqual(3);

        for (const micro of perspective.microFeatures) {
          expect(micro.trim()).not.toBe("");
        }
      }
    }

    const screenshots = allPerspectives.map(
      ({ perspective }) => perspective.screenshot,
    );
    expect(new Set(screenshots).size).toBe(screenshots.length);
  });

  it("says what each feature does, and what each screenshot shows", () => {
    for (const { feature, perspective } of allPerspectives) {
      expect(feature.title.trim()).not.toBe("");
      expect(feature.description.trim()).not.toBe("");
      // The alt text is a sentence about the image, not a repeat of the title
      // — a reader who cannot see the screenshot gets what it shows, not a
      // second copy of the heading.
      expect(perspective.screenshotAlt.trim()).not.toBe("");
      expect(perspective.screenshotAlt).not.toBe(feature.title);
      expect(perspective.screenshotAlt).not.toBe(perspective.label);
    }
  });

  /**
   * PUBLIC-008: the screenshots are static assets captured from the seeded
   * Northwood scenario and committed to the repository. A perspective whose
   * asset is missing would render a broken image on the one page that exists
   * to make a first impression, so the asset's presence is asserted here
   * rather than discovered in production.
   */
  it("commits a Northwood screenshot asset for every perspective", () => {
    for (const { feature, perspective } of allPerspectives) {
      expect(perspective.screenshot).toMatch(
        /^\/assets\/marketing\/northwood\/[a-z][a-z-]*\.webp$/,
      );

      expect(
        committedScreenshots,
        `missing ${perspective.screenshot} for ${feature.id}/${perspective.id} — capture it per docs/process/marketing-screenshot-recapture.md`,
      ).toContain(perspective.screenshot);
    }
  });
});
