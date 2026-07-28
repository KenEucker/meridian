import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import {
  BrandingColorError,
  MERIDIAN_DEFAULT_PALETTE,
  actionLabelFor,
  contrastRatio,
  departmentTokens,
  normalizeHex,
  organizationTokens,
  relativeLuminance,
  type BrandingPalette,
} from "./branding";

const fixture = JSON.parse(
  readFileSync(
    fileURLToPath(new URL("./branding.fixture.json", import.meta.url)),
    "utf-8",
  ),
) as {
  validPalettes: Record<
    string,
    { palette: BrandingPalette; tokens: Record<string, string> }
  >;
};

const tokensCss = readFileSync(
  fileURLToPath(new URL("../tokens.css", import.meta.url)),
  "utf-8",
);

describe("branding color math", () => {
  it("matches the WCAG reference ratio for black on white", () => {
    expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
  });

  it("gives a ratio of 1 for a color against itself", () => {
    expect(contrastRatio("#cc792f", "#cc792f")).toBeCloseTo(1, 10);
  });

  it("is independent of argument order", () => {
    expect(contrastRatio("#475157", "#fffcf6")).toBeCloseTo(
      contrastRatio("#fffcf6", "#475157"),
      10,
    );
  });

  it("puts white at luminance 1 and black at luminance 0", () => {
    expect(relativeLuminance("#ffffff")).toBeCloseTo(1, 10);
    expect(relativeLuminance("#000000")).toBeCloseTo(0, 10);
  });

  it("expands three-digit hex and lowercases", () => {
    expect(normalizeHex("#ABC", "primary")).toBe("#aabbcc");
  });

  it("refuses values that are not opaque hex colors", () => {
    for (const value of ["red", "#12345", "rgba(0,0,0,0.5)", "#12345678", ""]) {
      expect(() => normalizeHex(value, "primary")).toThrow(BrandingColorError);
    }
  });
});

describe("branding token resolution", () => {
  it("emits the expected tokens for every fixture palette", () => {
    for (const [name, entry] of Object.entries(fixture.validPalettes)) {
      expect(organizationTokens(entry.palette), name).toEqual(entry.tokens);
    }
  });

  it("resolves Meridian's own defaults from the fixture", () => {
    expect(MERIDIAN_DEFAULT_PALETTE).toEqual(
      fixture.validPalettes["meridian-default"].palette,
    );
  });

  it("keeps the default palette in step with the tokens.css :root block", () => {
    const root = tokensCss.match(/:root\s*\{([^}]*)\}/)?.[1] ?? "";
    const declared = new Map<string, string>();
    const re = /(--m-[\w-]+)\s*:\s*([^;]*);/g;
    let match: RegExpExecArray | null;
    while ((match = re.exec(root)) !== null) {
      declared.set(match[1], match[2].trim());
    }

    const resolved = organizationTokens(MERIDIAN_DEFAULT_PALETTE);

    // Only the settable values are literals in tokens.css; the action label
    // colors are written there as var() references to those same literals, so
    // they are compared by what they point at rather than by text.
    for (const [token, value] of Object.entries(resolved)) {
      const declaredValue = declared.get(token);
      expect(declaredValue, `missing ${token}`).toBeTruthy();

      const variable = declaredValue?.match(/^var\((--m-[\w-]+)\)$/);
      const effective = variable
        ? declared.get(variable[1])
        : declaredValue;

      expect(effective?.toLowerCase(), token).toBe(value);
    }
  });

  it("chooses the action label with the better contrast, not a fixed color", () => {
    // A dark platform color takes the light surface label; a light one takes
    // the dark foreground. Both come from the palette, never from #fff/#000.
    const palette = fixture.validPalettes["high-visibility"].palette;

    expect(actionLabelFor(palette.primary, palette)).toBe(palette.surface);
    expect(actionLabelFor(palette.accent, palette)).toBe(palette.foreground);
  });

  it("emits no department tokens when the organization switch is off", () => {
    expect(
      departmentTokens({ accent: "#1f5f4b", surface: "#eef6f2" }, false),
    ).toEqual({});
  });

  it("emits accent and surface independently", () => {
    expect(departmentTokens({ accent: "#1f5f4b", surface: null }, true)).toEqual({
      "--m-department-accent": "#1f5f4b",
    });

    expect(departmentTokens({ accent: null, surface: "#eef6f2" }, true)).toEqual({
      "--m-department-surface": "#eef6f2",
    });
  });

  it("never emits a status, severity, or attention token", () => {
    const emitted = Object.keys({
      ...organizationTokens(MERIDIAN_DEFAULT_PALETTE),
      ...departmentTokens({ accent: "#1f5f4b", surface: "#eef6f2" }, true),
    });

    for (const token of emitted) {
      expect(token).not.toMatch(/^--m-(status|attention|chart)-/);
    }
  });
});
