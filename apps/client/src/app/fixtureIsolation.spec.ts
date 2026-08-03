// No production module reaches a fixture (M18.8, M18.9; CLIENT-023, CLIENT-024).
//
// A test over the module graph rather than over rendered output, because what it
// has to catch cannot be observed by rendering. A view that imports a fixture and
// uses it only as a fallback renders perfectly against a stubbed node; the
// fixture shows up the day somebody is standing at a desk and the read comes back
// empty. Only the imports say whether the compiled-in seed data is still there.
//
// Transitive, and that is the point. Every time this went wrong it went wrong two
// or three modules down: a view was clean and something it imported for its
// formatting reached back into `department-ops/fixtures.ts` for an id.
//
// It started at M18.8 as a check over the four department operations views and
// widened at M18.9 to the whole production graph, which is what the task asks
// for: a repository check asserting that no production module imports a fixture.
// The entry points are the two the application actually boots from, so anything
// a user can reach is covered by construction and nothing has to be added here
// when a surface is.
//
// The sources come from `import.meta.glob` rather than from the filesystem, so
// the check needs no Node types in the browser project and runs with the rest of
// the client suite against no server at all (CLIENT-024). Specifiers resolve the
// way the client's own alias resolves them — `@/` to `src/`, plus relative paths
// — which is every form any module here imports by.

import { describe, expect, it } from "vitest";

/** Every client source file, keyed by its root-relative path. */
const SOURCES = import.meta.glob("/src/**/*.{ts,vue}", {
  query: "?raw",
  import: "default",
  eager: true,
}) as Record<string, string>;

/**
 * What the application boots from.
 *
 * `main.ts` is the entry the bundler builds, and `App.vue` is what it mounts.
 * Every route, view, and model a user can reach hangs off one of the two, so a
 * fixture anywhere in production code is reachable from here. Spec files are not
 * entries and are never walked: a test may hold whatever seed data it needs, and
 * that is the distinction this check exists to keep (CLIENT-024).
 */
const PRODUCTION_ENTRY_POINTS = [
  "/src/main.ts",
  "/src/App.vue",
] as const;

/**
 * The four surfaces M18.8 named, kept as their own cases.
 *
 * Redundant against the entry-point walk and deliberately so: these four are the
 * ones the department operations task is accountable for, and a failure that
 * names the surface is worth more to whoever is reading it than one that names
 * `main.ts`.
 */
const DEPARTMENT_OPERATIONS_VIEWS = [
  "/src/views/DepartmentOverviewView.vue",
  "/src/views/LogisticsDeskView.vue",
  "/src/views/OperationsCenterView.vue",
  "/src/views/PlanningTableView.vue",
] as const;

const CANDIDATE_SUFFIXES = ["", ".ts", ".vue", "/index.ts"] as const;

const IMPORT_SPECIFIER = /(?:from|import)\s*\(?\s*["']([^"']+)["']/g;

/** Collapse `.` and `..` segments, so a relative import resolves to one key. */
function normalize(path: string): string {
  const segments: string[] = [];

  for (const segment of path.split("/")) {
    if (segment === "" || segment === ".") {
      continue;
    }

    if (segment === "..") {
      segments.pop();

      continue;
    }

    segments.push(segment);
  }

  return `/${segments.join("/")}`;
}

function resolveSpecifier(specifier: string, importer: string): string | null {
  let base: string;

  if (specifier.startsWith("@/")) {
    base = `/src/${specifier.slice(2)}`;
  } else if (specifier.startsWith(".")) {
    base = normalize(`${importer.slice(0, importer.lastIndexOf("/"))}/${specifier}`);
  } else {
    // A bare specifier is a package. Nothing in `node_modules` is a Meridian
    // fixture, and walking into it would read the whole dependency tree.
    return null;
  }

  for (const suffix of CANDIDATE_SUFFIXES) {
    if (`${base}${suffix}` in SOURCES) {
      return `${base}${suffix}`;
    }
  }

  return null;
}

/**
 * Every module reachable from one entry, with the path that reached it.
 *
 * The path is kept because the failure message is the whole value of this test:
 * "a fixture is reachable" sends somebody hunting, and "through these three
 * files" is the fix.
 */
function reachableModules(entry: string): Map<string, readonly string[]> {
  const trails = new Map<string, readonly string[]>([[entry, [entry]]]);
  const queue = [entry];

  while (queue.length > 0) {
    const importer = queue.shift()!;
    const source = SOURCES[importer]!;
    const trail = trails.get(importer)!;

    IMPORT_SPECIFIER.lastIndex = 0;

    let match: RegExpExecArray | null;

    while ((match = IMPORT_SPECIFIER.exec(source)) !== null) {
      const resolved = resolveSpecifier(match[1]!, importer);

      if (resolved === null || trails.has(resolved)) {
        continue;
      }

      trails.set(resolved, [...trail, resolved]);
      queue.push(resolved);
    }
  }

  return trails;
}

/**
 * Fixtures production code may still reach: none, since M18.9.
 *
 * It held two until then — `localFieldFixture` and `localFieldSession`, the
 * development session installers behind
 * `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION`. Both are gone: the flag is gone,
 * `FieldSessionContext` derives from the session document, and what the specs
 * stand on is `session/localFieldSessionFixture.ts`, which no production module
 * imports and this walk proves it.
 *
 * Kept as an empty list rather than deleted, because the mechanism is the
 * assertion. An exception to this rule should have to be written down here and
 * argued for in review, and the test below asserts that being on the list is the
 * only way to be excused.
 */
const ALLOWED_FIXTURES: readonly string[] = [];

function fixtureTrails(
  entry: string,
  allowed: readonly string[] = ALLOWED_FIXTURES,
): string[] {
  return [...reachableModules(entry).entries()]
    .filter(([module]) => /fixture/i.test(module) && !allowed.includes(module))
    .map(([, trail]) => trail.join(" -> "));
}

describe("fixture isolation", () => {
  it("reads every client source it is asked to walk", () => {
    for (const entry of [...PRODUCTION_ENTRY_POINTS, ...DEPARTMENT_OPERATIONS_VIEWS]) {
      expect(SOURCES[entry]).toBeTypeOf("string");
    }
  });

  it.each(PRODUCTION_ENTRY_POINTS)("reaches no fixture module from %s", (entry) => {
    expect(fixtureTrails(entry)).toEqual([]);
  });

  it.each(DEPARTMENT_OPERATIONS_VIEWS)(
    "reaches no fixture module from %s",
    (view) => {
      expect(fixtureTrails(view)).toEqual([]);
    },
  );

  /*
   * The walker has to be able to fail, or the assertions above are assertions
   * that a regex found nothing.
   *
   * The control is synthetic rather than a real surface that still imports a
   * fixture, because the whole direction of travel is that no such surface
   * exists. It was `MeView` until that page was bound to the session, and
   * pinning it to whichever module has not been rebound yet means the control
   * evaporates exactly when the check starts mattering most.
   */
  it("does not excuse a fixture that is not on the allowlist", () => {
    /*
     * The allowlist is empty, so the mechanism is exercised against a synthetic
     * one rather than against whatever happens to be on it. That is the point of
     * passing it in: this stays a test of "only a listed module is excused" on
     * the day somebody adds an entry, and on the day nobody ever does.
     */
    const entry = "/src/views/__isolation-unlisted__.vue";
    const listed = "/src/department-teams/listedFixture.ts";
    const unlisted = "/src/department-teams/someOtherFixture.ts";

    SOURCES[entry] = `import x from "@/department-teams/listedFixture";
import y from "@/department-teams/someOtherFixture";`;
    SOURCES[listed] = "export default 1;";
    SOURCES[unlisted] = "export default 1;";

    try {
      expect(fixtureTrails(entry, [listed])).toEqual([`${entry} -> ${unlisted}`]);
    } finally {
      delete SOURCES[entry];
      delete SOURCES[listed];
      delete SOURCES[unlisted];
    }
  });

  it("finds a fixture module through a chain of imports", () => {
    const entry = "/src/views/__isolation-control__.vue";
    const middle = "/src/views/__isolation-middle__.ts";

    SOURCES[entry] = `import x from "@/views/__isolation-middle__";`;
    SOURCES[middle] = `import y from "@/department-teams/fixtureControl";`;
    SOURCES["/src/department-teams/fixtureControl.ts"] = "export default 1;";

    try {
      expect(fixtureTrails(entry)).toEqual([
        `${entry} -> ${middle} -> /src/department-teams/fixtureControl.ts`,
      ]);
    } finally {
      delete SOURCES[entry];
      delete SOURCES[middle];
      delete SOURCES["/src/department-teams/fixtureControl.ts"];
    }
  });
});
