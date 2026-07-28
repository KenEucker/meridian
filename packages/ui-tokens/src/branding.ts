/**
 * Client-side branding token resolution (M15A.4; BRAND-006, BRAND-007).
 *
 * The counterpart to `App\Services\Branding\BrandingTokenResolver` on the
 * server. Both exist because both surfaces need the same answer: the Laravel
 * and Orchid pages serve a generated stylesheet, and the Vue client applies
 * branding to `document.documentElement` without a round trip so a cached
 * offline session still renders its organization's identity (BRAND-022).
 *
 * Two implementations of one derivation is a drift risk, so the derivation is
 * kept as small as it can be. Everything that CSS can derive is derived in
 * `tokens.css` through `var()` and `color-mix()`; the only thing computed here
 * is the action label color, which is a luminance decision CSS cannot make
 * portably today. `branding.fixture.json` pins the expected output for a set of
 * palettes, and both the TypeScript and the PHP test read it.
 */

/** The ten values an organization branding profile may set (BRAND-006). */
export interface BrandingPalette {
  readonly primary: string;
  readonly secondary: string;
  readonly tertiary: string;
  readonly accent: string;
  readonly canvas: string;
  readonly surface: string;
  readonly foreground: string;
  readonly muted_foreground: string;
  readonly border: string;
  readonly focus: string;
}

/** The two values a department branding profile may set (BRAND-009). */
export interface DepartmentBranding {
  readonly accent: string | null;
  readonly surface: string | null;
}

export type BrandingTokens = Readonly<Record<string, string>>;

/** Meridian's own defaults, mirroring the `:root` block in `tokens.css`. */
export const MERIDIAN_DEFAULT_PALETTE: BrandingPalette = {
  primary: "#475157",
  secondary: "#6b7562",
  tertiary: "#a58667",
  accent: "#cc792f",
  canvas: "#f6f1e8",
  surface: "#fffcf6",
  foreground: "#151a1f",
  muted_foreground: "#5f665f",
  border: "#8d8371",
  focus: "#b35f14",
};

export const PALETTE_FIELDS = [
  "primary",
  "secondary",
  "tertiary",
  "accent",
  "canvas",
  "surface",
  "foreground",
  "muted_foreground",
  "border",
  "focus",
] as const satisfies readonly (keyof BrandingPalette)[];

const PALETTE_TOKENS: Readonly<Record<keyof BrandingPalette, string>> = {
  primary: "--m-platform-primary",
  secondary: "--m-platform-secondary",
  tertiary: "--m-platform-tertiary",
  accent: "--m-platform-accent",
  canvas: "--m-surface-app",
  surface: "--m-surface-base",
  foreground: "--m-text-primary",
  muted_foreground: "--m-text-muted",
  border: "--m-border-default",
  focus: "--m-focus-ring",
};

export class BrandingColorError extends Error {
  constructor(readonly field: string, readonly value: string) {
    super(
      `Branding color "${field}" must be an opaque hex color such as #1a2b3c; received "${value}".`,
    );
    this.name = "BrandingColorError";
  }
}

/** Normalizes `#rgb` and `#rrggbb` to lowercase `#rrggbb`. Alpha is refused. */
export function normalizeHex(value: string, field: string): string {
  let candidate = value.trim().toLowerCase();
  const short = /^#([0-9a-f]{3})$/.exec(candidate);

  if (short) {
    candidate = `#${short[1].replace(/(.)/g, "$1$1")}`;
  }

  if (!/^#[0-9a-f]{6}$/.test(candidate)) {
    throw new BrandingColorError(field, value);
  }

  return candidate;
}

/** WCAG 2.1 relative luminance. */
export function relativeLuminance(hex: string, field = "color"): number {
  const normalized = normalizeHex(hex, field);
  const channel = (offset: number): number => {
    const value = parseInt(normalized.slice(offset, offset + 2), 16) / 255;
    return value <= 0.03928
      ? value / 12.92
      : ((value + 0.055) / 1.055) ** 2.4;
  };

  return (
    0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
  );
}

/** WCAG 2.1 contrast ratio. Order-independent, always >= 1. */
export function contrastRatio(first: string, second: string): number {
  const a = relativeLuminance(first);
  const b = relativeLuminance(second);

  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/**
 * The label color for a filled platform-color action: whichever of the
 * palette's own foreground and surface reads better on it. When neither clears
 * 4.5:1 the server validator refuses the palette; nothing is adjusted here to
 * compensate (BRAND-016).
 */
export function actionLabelFor(
  background: string,
  palette: BrandingPalette,
): string {
  const surface = normalizeHex(palette.surface, "surface");
  const foreground = normalizeHex(palette.foreground, "foreground");

  return contrastRatio(background, surface) >= contrastRatio(background, foreground)
    ? surface
    : foreground;
}

/**
 * Palette fields that carry into dark mode.
 *
 * The four platform colors are hues an organization owns and they read on a
 * dark surface as well as a light one. The six neutrals are not: an
 * organization submits one light set (BRAND-006), validated against light
 * backgrounds, so applying them in dark mode paints a light canvas behind
 * Meridian's dark-mode foreground.
 */
const DARK_MODE_FIELDS = [
  "primary",
  "secondary",
  "tertiary",
  "accent",
] as const satisfies readonly (keyof BrandingPalette)[];

/** The tokens that apply in light and dark mode alike. */
export function platformTokens(palette: BrandingPalette): BrandingTokens {
  const tokens: Record<string, string> = {};

  for (const field of DARK_MODE_FIELDS) {
    tokens[PALETTE_TOKENS[field]] = normalizeHex(palette[field], field);
  }

  return tokens;
}

/**
 * The tokens that apply only in light mode.
 *
 * Action label colors belong here, not with the platform colors: they are
 * chosen from the palette's own foreground and surface, which are light-mode
 * neutrals.
 */
export function lightModeTokens(palette: BrandingPalette): BrandingTokens {
  const tokens: Record<string, string> = {};

  for (const field of PALETTE_FIELDS) {
    if (!(DARK_MODE_FIELDS as readonly string[]).includes(field)) {
      tokens[PALETTE_TOKENS[field]] = normalizeHex(palette[field], field);
    }
  }

  tokens["--m-action-primary-text"] = actionLabelFor(
    normalizeHex(palette.primary, "primary"),
    palette,
  );
  tokens["--m-action-secondary-text"] = actionLabelFor(
    normalizeHex(palette.secondary, "secondary"),
    palette,
  );
  tokens["--m-action-destructive-text"] = actionLabelFor(
    normalizeHex(palette.accent, "accent"),
    palette,
  );

  return tokens;
}

/**
 * Every custom property an organization palette contributes.
 *
 * The union of {@link platformTokens} and {@link lightModeTokens}, and what
 * the client/server parity fixture pins. Anything applying these to a live
 * document must respect the light/dark split rather than writing them all.
 */
export function organizationTokens(palette: BrandingPalette): BrandingTokens {
  return { ...platformTokens(palette), ...lightModeTokens(palette) };
}

/**
 * The custom properties a department override contributes.
 *
 * An organization that has switched department overrides off contributes
 * nothing, which is how BRAND-013 lands at the token layer: with no properties
 * emitted, the department keeps the organization's derived accent and no
 * background at all.
 */
export function departmentTokens(
  branding: DepartmentBranding,
  overridesEnabled: boolean,
): BrandingTokens {
  if (!overridesEnabled) {
    return {};
  }

  const tokens: Record<string, string> = {};

  if (branding.accent) {
    tokens["--m-department-accent"] = normalizeHex(
      branding.accent,
      "department accent",
    );
  }

  if (branding.surface) {
    tokens["--m-department-surface"] = normalizeHex(
      branding.surface,
      "department surface",
    );
  }

  return tokens;
}
