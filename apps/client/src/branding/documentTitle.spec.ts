import { describe, expect, it } from "vitest";

import {
  applyDocumentTitle,
  buildDocumentTitle,
} from "@/branding/documentTitle";

describe("document title", () => {
  it("uses Meridian's name when there is no branding profile", () => {
    expect(
      buildDocumentTitle({ productName: "Meridian", modeName: "Field" }),
    ).toBe("Meridian Field");
  });

  it("replaces the product name with the organization display name", () => {
    // BRAND-002 names the browser/document title as one of the four surfaces
    // organization identity replaces.
    expect(
      buildDocumentTitle({
        productName: "Deep Harbor Collective",
        modeName: "Field",
      }),
    ).toBe("Deep Harbor Collective Field");
  });

  it("puts the screen ahead of the product name", () => {
    expect(
      buildDocumentTitle({
        screen: "Field Reports",
        productName: "Deep Harbor Collective",
        modeName: "Admin",
      }),
    ).toBe("Field Reports · Deep Harbor Collective Admin");
  });

  it("omits an empty screen or mode rather than leaving separators behind", () => {
    expect(
      buildDocumentTitle({
        screen: "   ",
        productName: "Meridian",
        modeName: null,
      }),
    ).toBe("Meridian");
  });

  it("writes the title onto the document", () => {
    applyDocumentTitle({
      screen: "Readiness",
      productName: "Deep Harbor Collective",
      modeName: "Field",
    });

    expect(document.title).toBe(
      "Readiness · Deep Harbor Collective Field",
    );
  });
});
