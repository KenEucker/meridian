#!/usr/bin/env python3
"""Regression checks for Meridian's pull request body validator."""

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VALIDATOR = ROOT / "scripts/process/validate_pr_body.py"
REQUIRED_SECTIONS = [
    "Summary",
    "Traceability",
    "Implementation Notes",
    "Technology Baseline",
    "Data Model / Migration Notes",
    "Permission Notes",
    "Offline / Sync Notes",
    "Audit Notes",
    "Automated Tests",
    "Acceptance Criteria Checklist",
    "Human QA Plan",
    "Risks",
    "Follow-up Issues",
]


def body(traceability):
    return "\n\n".join(
        f"# {section}\n\n{traceability if section == 'Traceability' else 'Present.'}"
        for section in REQUIRED_SECTIONS
    )


def validate(pr_body):
    return subprocess.run(
        [sys.executable, str(VALIDATOR), "--body", pr_body],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )


def expect_valid(traceability):
    result = validate(body(traceability))
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)


def expect_invalid(traceability):
    result = validate(body(traceability))
    if result.returncode == 0:
        raise AssertionError("Expected PR body validation to fail.")


def main():
    # POL-028 was a valid ID rejected by the former hard-coded prefix list.
    expect_valid("- POL-028")
    # Plural section labels are valid traceability prose, too.
    expect_valid("- Technical spec: Sections 21.7 and 21.12")
    # A syntactically similar, but undocumented, ID is not traceability.
    expect_invalid("- UNKNOWN-999")
    print("PR body validator regression checks passed.")


if __name__ == "__main__":
    main()
