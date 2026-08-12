#!/usr/bin/env python3
"""Validate Meridian process scaffold files and optionally write a summary."""

import argparse
import sys
from datetime import datetime, timezone
from pathlib import Path

REQUIRED_FILES = [
    "docs/meridian-technology-baseline.md",
    "docs/process/meridian-development-process.md",
    "docs/process/traceability-matrix.md",
    "docs/process/github-branch-protection.md",
    "docs/process/conventional-commits.md",
    "docs/process/versioning-strategy.md",
    "docs/process/release-packaging.md",
    "docs/process/god-mode-console-override-inventory.md",
    "docs/adr/0001-shared-vue-client.md",
    "docs/qa/README.md",
    "docs/qa/QA-BOOT-01-fresh-checkout-boots.md",
    ".github/pull_request_template.md",
    ".github/ISSUE_TEMPLATE/requirement-implementation.yml",
    ".github/ISSUE_TEMPLATE/technical-foundation.yml",
    ".github/ISSUE_TEMPLATE/human-qa.yml",
    ".github/ISSUE_TEMPLATE/bug-report.yml",
    ".github/workflows/pr-process-checks.yml",
    ".github/workflows/main-process-checks.yml",
    ".github/workflows/production-version-bump.yml",
    ".github/workflows/release-artifacts.yml",
    "scripts/process/validate_pr_body.py",
    "scripts/process/test_validate_pr_body.py",
    "scripts/process/validate_traceability_matrix.py",
    "scripts/process/validate_qa_docs.py",
    "scripts/process/validate_repo_process.py",
    "scripts/process/validate_version_strategy.py",
    "scripts/process/validate_conventional_commits.py",
    "scripts/process/check.sh",
    "docs/issues/001-monorepo-scaffold.md",
    "docs/issues/002-laravel-postgresql-orchid-boot-path.md",
    "docs/issues/003-ci-baseline.md",
]

REQUIRED_DIRECTORIES = [
    "apps/client",
    "apps/server",
    "apps/mobile",
    "apps/kiosk",
    "packages/shared-types",
    "packages/openapi-client",
    "deploy/docker",
    "deploy/caddy",
    "deploy/dns",
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--write-summary", help="Write a Markdown process summary to this path.")
    args = parser.parse_args()

    missing = [path for path in REQUIRED_FILES if not Path(path).exists()]
    missing_dirs = [path for path in REQUIRED_DIRECTORIES if not Path(path).is_dir()]
    if missing or missing_dirs:
        print("Repository process validation failed:")
        for path in missing:
            print(f"- Missing {path}. Create the required process scaffold file.")
        for path in missing_dirs:
            print(f"- Missing {path}. Create the required monorepo scaffold directory.")
        return 1

    if args.write_summary:
        summary = Path(args.write_summary)
        summary.parent.mkdir(parents=True, exist_ok=True)
        summary.write_text(
            "\n".join(
                [
                    "# Meridian Process Summary",
                    "",
                    f"Generated: {datetime.now(timezone.utc).isoformat()}",
                    "",
                    "Validated scaffold files:",
                    *[f"- `{path}`" for path in REQUIRED_FILES],
                    "",
                    "Validated scaffold directories:",
                    *[f"- `{path}`" for path in REQUIRED_DIRECTORIES],
                    "",
                ]
            ),
            encoding="utf-8",
        )
        print(f"Wrote process summary to {summary}.")

    print("Repository process validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
