// The department operations surfaces reach no fixture module (M18.8; CLIENT-023,
// CLIENT-024).
//
// A test over the module graph rather than over rendered output, because what it
// has to catch cannot be observed by rendering. A view that imports a fixture and
// uses it only as a fallback renders perfectly against a stubbed node; the
// fixture shows up the day somebody is standing at a desk and the read comes back
// empty. Only the imports say whether the compiled-in seed data is still there.
//
// Transitive, and that is the point. Both times this went wrong it went wrong two
// or three modules down: the four views were clean and something they imported
// for its formatting reached back into `department-ops/fixtures.ts` for an id.
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
 * The four surfaces the department operations task names.
 *
 * Listed rather than globbed: "a department operations view" is a fact about
 * these four screens, and a glob over the views directory would quietly stop
 * covering one if it were renamed.
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

function fixtureTrails(entry: string): string[] {
  return [...reachableModules(entry).entries()]
    .filter(([module]) => /fixture/i.test(module))
    .map(([, trail]) => trail.join(" -> "));
}

describe("department operations fixture isolation", () => {
  it("reads every client source it is asked to walk", () => {
    for (const view of DEPARTMENT_OPERATIONS_VIEWS) {
      expect(SOURCES[view]).toBeTypeOf("string");
    }
  });

  it.each(DEPARTMENT_OPERATIONS_VIEWS)(
    "reaches no fixture module from %s",
    (view) => {
      expect(fixtureTrails(view)).toEqual([]);
    },
  );

  /*
   * The walker has to be able to fail, or the four assertions above are four
   * assertions that a regex found nothing. `MeView` is a surface that still reads
   * `department-ops/fixtures.ts` and is bound by its own later task, so it is the
   * honest proof that a fixture import is visible from here.
   */
  it("finds a fixture module where one is still imported", () => {
    expect(fixtureTrails("/src/views/MeView.vue").length).toBeGreaterThan(0);
  });
});
