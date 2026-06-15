#!/usr/bin/env python3
"""Validate Meridian human QA scenario documents."""

import re
import sys
from pathlib import Path

QA_DIR = Path("docs/qa")
REQUIRED_SECTIONS = [
    "Purpose",
    "Requirements covered",
    "Environment",
    "Personas",
    "Setup data",
    "Steps",
    "Expected results",
    "Evidence to capture",
    "Failure notes",
]


def normalize(text):
    return re.sub(r"\s+", " ", text.strip().lower())


def section_headings(text):
    found = set()
    for line in text.splitlines():
        match = re.match(r"^\s{0,3}#{2,6}\s+(.+?)\s*#*\s*$", line)
        if match:
            found.add(normalize(match.group(1)))
    return found


def main():
    errors = []
    if not QA_DIR.exists():
        print(f"QA validation failed:\n- Missing {QA_DIR}. Create docs/qa and add QA-*.md scenario files.")
        return 1

    qa_files = sorted(QA_DIR.glob("QA-*.md"))
    if not qa_files:
        errors.append("No docs/qa/QA-*.md files found. Add at least QA-BOOT-01-fresh-checkout-boots.md.")

    for path in qa_files:
        found = section_headings(path.read_text(encoding="utf-8"))
        for section in REQUIRED_SECTIONS:
            if normalize(section) not in found:
                errors.append(f"{path} is missing '## {section}'. Add the required QA section.")

    if errors:
        print("QA documentation validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    print("QA documentation validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
