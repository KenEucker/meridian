import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

const tokensCssPath = fileURLToPath(new URL("../tokens.css", import.meta.url));
const serverMirrorPath = fileURLToPath(
  new URL("../../../apps/server/public/css/meridian-tokens.css", import.meta.url),
);

const tokensCss = readFileSync(tokensCssPath, "utf-8");

/**
 * Canonical --m-* semantic token names required by
 * docs/ui/meridian-ui-implementation-contract.md section 10.1. Every name must
 * be defined with a non-empty value in the light `:root` baseline.
 */
const REQUIRED_TOKENS = [
  "--m-surface-app",
  "--m-surface-base",
  "--m-surface-raised",
  "--m-surface-overlay",
  "--m-text-primary",
  "--m-text-secondary",
  "--m-text-muted",
  "--m-text-inverse",
  "--m-border-default",
  "--m-border-strong",
  "--m-border-subtle",
  "--m-action-primary-bg",
  "--m-action-primary-text",
  "--m-action-secondary-bg",
  "--m-action-secondary-text",
  "--m-action-destructive-bg",
  "--m-action-destructive-text",
  "--m-focus-ring",
  "--m-status-neutral",
  "--m-status-success",
  "--m-status-warning",
  "--m-status-danger",
  "--m-status-restricted",
  "--m-attention-routine",
  "--m-attention-attention",
  "--m-attention-warning",
  "--m-attention-critical",
  "--m-attention-restricted",
  "--m-department-accent",
  "--m-space-1",
  "--m-space-2",
  "--m-space-3",
  "--m-space-4",
  "--m-space-6",
  "--m-space-8",
  "--m-radius-sm",
  "--m-radius-md",
  "--m-radius-lg",
  "--m-radius-pill",
  "--m-shadow-sm",
  "--m-shadow-md",
  "--m-shadow-overlay",
  "--m-font-body",
  "--m-font-heading",
  "--m-text-xs",
  "--m-text-sm",
  "--m-text-md",
  "--m-text-lg",
  "--m-text-xl",
] as const;

/** Tokens that must change between light and dark mode. */
const DARK_OVERRIDE_TOKENS = [
  "--m-surface-app",
  "--m-surface-base",
  "--m-surface-raised",
  "--m-surface-overlay",
  "--m-text-primary",
  "--m-text-secondary",
  "--m-border-default",
];

function declarations(blockBody: string): Map<string, string> {
  const map = new Map<string, string>();
  const re = /(--m-[\w-]+)\s*:\s*([^;]*);/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(blockBody)) !== null) {
    map.set(match[1], match[2].trim());
  }
  return map;
}

function blockBody(css: string, selectorPattern: RegExp): string {
  const match = css.match(selectorPattern);
  if (!match) {
    throw new Error(`Could not find a token block matching ${selectorPattern}.`);
  }
  return match[1];
}

const lightRoot = declarations(blockBody(tokensCss, /:root\s*\{([^}]*)\}/));
const mediaDark = declarations(
  blockBody(tokensCss, /:root:not\(\[data-theme\]\)\s*\{([^}]*)\}/),
);
const explicitDark = declarations(
  blockBody(tokensCss, /\[data-theme="dark"\]\s*\{([^}]*)\}/),
);

describe("shared ui tokens contract", () => {
  it("defines every canonical semantic token with a non-empty light value", () => {
    for (const token of REQUIRED_TOKENS) {
      expect(lightRoot.has(token), `missing ${token} in :root`).toBe(true);
      expect(lightRoot.get(token), `empty value for ${token}`).toBeTruthy();
    }
  });

  it("leaves no empty token slots in the skeleton", () => {
    expect(tokensCss).not.toMatch(/--m-[\w-]+\s*:\s*;/);
  });

  it("uses the Inter type stack for body and heading fonts", () => {
    expect(lightRoot.get("--m-font-body")).toContain("Inter");
    expect(lightRoot.get("--m-font-heading")).toContain("Inter");
  });

  it("redefines the same token names in dark mode with different surface/text values", () => {
    for (const token of DARK_OVERRIDE_TOKENS) {
      expect(explicitDark.has(token), `missing ${token} in [data-theme="dark"]`).toBe(true);
      expect(explicitDark.get(token)).not.toBe(lightRoot.get(token));
    }
  });

  it("keeps the automatic and explicit dark blocks in lockstep", () => {
    expect([...explicitDark.keys()].sort()).toEqual([...mediaDark.keys()].sort());
    for (const [token, value] of explicitDark) {
      expect(mediaDark.get(token)).toBe(value);
    }
  });

  it("only overrides tokens that also exist in the light baseline", () => {
    for (const token of explicitDark.keys()) {
      expect(lightRoot.has(token), `dark-only token ${token}`).toBe(true);
    }
  });

  it("stays byte-identical to the admin (server) mirror copy", () => {
    const mirror = readFileSync(serverMirrorPath, "utf-8");
    expect(mirror).toBe(tokensCss);
  });
});
