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
    "docs/qa/README.md",
    "docs/qa/QA-BOOT-01-fresh-checkout-boots.md",
    ".github/pull_request_template.md",
    ".github/ISSUE_TEMPLATE/requirement-implementation.yml",
    ".github/ISSUE_TEMPLATE/technical-foundation.yml",
    ".github/ISSUE_TEMPLATE/human-qa.yml",
    ".github/ISSUE_TEMPLATE/bug-report.yml",
    ".github/workflows/pr-process-checks.yml",
    ".github/workflows/main-process-checks.yml",
    "scripts/process/validate_pr_body.py",
    "scripts/process/validate_traceability_matrix.py",
    "scripts/process/validate_qa_docs.py",
    "scripts/process/validate_repo_process.py",
    "scripts/process/validate_conventional_commits.py",
    "scripts/process/check.sh",
    "docs/issues/001-monorepo-scaffold.md",
    "docs/issues/002-laravel-postgresql-orchid-boot-path.md",
    "docs/issues/003-ci-baseline.md",
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--write-summary", help="Write a Markdown process summary to this path.")
    args = parser.parse_args()

    missing = [path for path in REQUIRED_FILES if not Path(path).exists()]
    if missing:
        print("Repository process validation failed:")
        for path in missing:
            print(f"- Missing {path}. Create the required process scaffold file.")
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
                ]
            ),
            encoding="utf-8",
        )
        print(f"Wrote process summary to {summary}.")

    print("Repository process validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
