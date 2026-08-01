// Every `--m-*` a client stylesheet reads has to be a token that exists.
//
// A `var(--m-whatever)` naming a token nothing defines is not a fallback to
// something sensible: the declaration becomes invalid at computed-value time
// and the property resets to its initial value, so
// `background: var(--m-surface-primary)` is a surface that renders as no
// surface at all. It reads as correct in light mode, where "no surface" and
// "the page behind it" are both near-white, and wrong in dark mode — which is
// the kind of defect that survives review. The incident form carried ten of
// them across two views.
//
// A `var()` that supplies its own fallback is fine and is not reported: the
// fallback is the value, and naming a token that may arrive later is a
// deliberate pattern (`var(--m-font-mono, monospace)`).
//
// This lives with the tokens rather than with the client because the token
// file is what decides the answer, the same way the server mirror check does.

import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

const repoRoot = fileURLToPath(new URL("../../../", import.meta.url));
const clientSource = join(repoRoot, "apps/client/src");

/** Files whose `--m-*` declarations define a token for everyone. */
const TOKEN_SOURCES = [
  join(repoRoot, "packages/ui-tokens/tokens.css"),
  join(repoRoot, "apps/client/src/assets/base.css"),
];

const DECLARATION = /(--m-[\w-]+)\s*:/g;
/** A `var()` reference, capturing whether a fallback follows the name. */
const REFERENCE = /var\(\s*(--m-[\w-]+)\s*(,?)/g;

function styleFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) {
      return styleFiles(path);
    }

    return /\.(vue|css)$/.test(entry.name) ? [path] : [];
  });
}

function definedTokens(files: readonly string[]): Set<string> {
  const defined = new Set<string>();

  for (const file of files) {
    for (const match of readFileSync(file, "utf-8").matchAll(DECLARATION)) {
      defined.add(match[1]!);
    }
  }

  return defined;
}

describe("design token references", () => {
  it("names no token that nothing defines", () => {
    const files = styleFiles(clientSource);
    // A component may declare a token for its own subtree, which counts.
    const defined = definedTokens([...TOKEN_SOURCES, ...files]);
    const dangling: string[] = [];

    for (const file of files) {
      readFileSync(file, "utf-8")
        .split("\n")
        .forEach((line, index) => {
          for (const match of line.matchAll(REFERENCE)) {
            const token = match[1]!;
            const hasFallback = match[2] === ",";

            if (!hasFallback && !defined.has(token)) {
              dangling.push(
                `${relative(repoRoot, file).split("\\").join("/")}:${index + 1} ${token}`,
              );
            }
          }
        });
    }

    expect(dangling).toEqual([]);
  });
});
