import { describe, expect, it } from "vitest";

import { renderSimplePdf } from "@/ims/simplePdf";

describe("renderSimplePdf", () => {
  it("renders printable text into a PDF document", () => {
    const bytes = renderSimplePdf([
      "Incident PDF Export",
      "IMS number: INC-2027-000042",
      "Title: Medical assist near Gate A",
    ]);
    const text = new TextDecoder().decode(bytes);

    expect(text.startsWith("%PDF-1.4")).toBe(true);
    expect(text).toContain("IMS number: INC-2027-000042");
    expect(text).toContain("Medical assist near Gate A");
  });
});
