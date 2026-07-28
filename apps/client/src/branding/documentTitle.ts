/**
 * The browser/document title (M15A.8; BRAND-002, BRAND-003).
 *
 * One of the four surfaces BRAND-002 names, and the easiest to get subtly
 * wrong: a title is assembled from a screen name and a product name, and the
 * product name is the part branding replaces.
 *
 * Meridian's own name survives where BRAND-003 says it must. That boundary is
 * expressed as an explicit `meridianIdentity` flag rather than inferred from
 * the route, because the surfaces it protects — login, the magic-link landing,
 * node first-run setup — are rendered by Blade rather than by this router, and
 * a rule that guessed from route names would silently start branding them the
 * day one of them moved into the client.
 */

export interface DocumentTitleParts {
  /** The current screen, e.g. "Field Reports". Omitted for the home screen. */
  readonly screen?: string | null;
  /** The organization display name, or "Meridian" when unbranded. */
  readonly productName: string;
  /** The UI mode suffix, e.g. "Field". */
  readonly modeName?: string | null;
}

export function buildDocumentTitle(parts: DocumentTitleParts): string {
  const trailing = [parts.productName, parts.modeName]
    .filter((value): value is string => Boolean(value && value.trim() !== ""))
    .join(" ");

  const screen = parts.screen?.trim();

  return screen ? `${screen} · ${trailing}` : trailing;
}

export function applyDocumentTitle(parts: DocumentTitleParts): string {
  const title = buildDocumentTitle(parts);

  if (typeof document !== "undefined") {
    document.title = title;
  }

  return title;
}
