import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

import { afterEach, describe, expect, it } from "vitest";

import {
  contentTypeForPath,
  resolveClientFile,
} from "./staticClientServer";

const tempDirs: string[] = [];

afterEach(() => {
  for (const dir of tempDirs.splice(0)) {
    rmSync(dir, { recursive: true, force: true });
  }
});

function tempClientDist(): string {
  const dir = mkdtempSync(join(tmpdir(), "meridian-client-dist-"));
  tempDirs.push(dir);
  writeFileSync(join(dir, "index.html"), "<!doctype html><h1>Meridian</h1>");
  writeFileSync(join(dir, "app.js"), "console.log('client');");
  return dir;
}

describe("resolveClientFile", () => {
  it("serves concrete assets inside the client dist directory", () => {
    const dist = tempClientDist();

    expect(resolveClientFile(dist, "/app.js")).toBe(join(dist, "app.js"));
  });

  it("falls back to the Vue index for client-side routes", () => {
    const dist = tempClientDist();

    expect(resolveClientFile(dist, "/staff/field-reports")).toBe(join(dist, "index.html"));
  });

  it("rejects path traversal outside the client dist directory", () => {
    const dist = tempClientDist();

    expect(resolveClientFile(dist, "/../package.json")).toBeNull();
  });
});

describe("contentTypeForPath", () => {
  it("returns web content types for bundled client assets", () => {
    expect(contentTypeForPath("index.html")).toBe("text/html; charset=UTF-8");
    expect(contentTypeForPath("app.js")).toBe("text/javascript; charset=UTF-8");
    expect(contentTypeForPath("style.css")).toBe("text/css; charset=UTF-8");
  });
});
