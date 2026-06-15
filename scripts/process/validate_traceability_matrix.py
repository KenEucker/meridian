#!/usr/bin/env python3
"""Validate the Meridian traceability matrix."""

import sys
from pathlib import Path

MATRIX = Path("docs/process/traceability-matrix.md")
REQUIRED_COLUMNS = [
    "Requirement / Spec Section",
    "Status",
    "Issue",
    "PR",
    "Automated tests",
    "Human QA scenario",
    "Notes",
]
REQUIRED_ROWS = [
    "Technical spec: Section 28 Implementation Order",
    "Technical spec: Section 26.1 Alpha 1 acceptance target",
    "ORG-001",
    "ORG-002",
    "TEAM-002",
    "VOL-006",
    "SLB-005",
    "FR-007",
    "FR-012",
    "INC-014",
]


def split_row(line):
    return [cell.strip() for cell in line.strip().strip("|").split("|")]


def main():
    errors = []
    if not MATRIX.exists():
        print(f"Traceability validation failed:\n- Missing {MATRIX}. Create the matrix with the required columns and starter rows.")
        return 1

    lines = MATRIX.read_text(encoding="utf-8").splitlines()
    table_lines = [line for line in lines if line.strip().startswith("|") and line.strip().endswith("|")]
    if len(table_lines) < 3:
        errors.append(f"{MATRIX} must contain a Markdown table with a header, separator, and rows.")
    else:
        columns = split_row(table_lines[0])
        if columns != REQUIRED_COLUMNS:
            errors.append(
                f"{MATRIX} has incorrect columns. Expected: {' | '.join(REQUIRED_COLUMNS)}"
            )
        body = "\n".join(table_lines[2:])
        for row in REQUIRED_ROWS:
            if row not in body:
                errors.append(f"Missing starter row '{row}' in {MATRIX}.")

    if errors:
        print("Traceability matrix validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    print("Traceability matrix validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
