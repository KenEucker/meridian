import { describe, expect, it } from "vitest";

import mainTs from "@/main.ts?raw";
import appShell from "@/components/AppShell.vue?raw";
import homeView from "@/views/HomeView.vue?raw";
import notFoundView from "@/views/NotFoundView.vue?raw";

describe("shared client token wiring", () => {
  it("imports the shared @meridian/ui-tokens baseline at startup", () => {
    expect(mainTs).toContain('import "@meridian/ui-tokens/tokens.css";');
  });

  it("styles the shared shell with semantic tokens", () => {
    expect(appShell).toContain("var(--m-surface-raised)");
    expect(appShell).toContain("var(--m-border-default)");
    expect(appShell).toContain("var(--m-focus-ring)");
    expect(homeView).toContain("var(--m-text-muted)");
    expect(homeView).toContain("var(--m-font-heading)");
  });

  it("drops the pre-baseline placeholder shell tokens", () => {
    for (const source of [mainTs, appShell, homeView, notFoundView]) {
      expect(source).not.toContain("--m-shell-");
    }
  });
});
