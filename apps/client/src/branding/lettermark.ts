const STOP_WORDS = new Set(["the", "of", "and", "a", "an", "for", "de", "la"]);

/**
 * The letters shown when an entity has no logo (BRAND-005, BRAND-010,
 * BRAND-025).
 *
 * Mirrors `App\Services\Branding\Lettermark`. Duplicated rather than fetched
 * because a mark must render for a department or team the branding payload has
 * never mentioned — one with no branding profile at all — and a network round
 * trip to compute two letters would be absurd.
 */
export function lettermarkFor(name: string): string {
  const candidates = name
    .replace(/[^\p{L}\p{N}]+/gu, " ")
    .trim()
    .split(/\s+/u)
    .filter((word) => word.length > 0);

  if (candidates.length === 0) {
    return "?";
  }

  const meaningful = candidates.filter(
    (word) => !STOP_WORDS.has(word.toLowerCase()),
  );
  const words = meaningful.length > 0 ? meaningful : candidates;

  if (words.length === 1) {
    return words[0].slice(0, 2).toUpperCase();
  }

  return words
    .slice(0, 3)
    .map((word) => word.slice(0, 1))
    .join("")
    .toUpperCase();
}
