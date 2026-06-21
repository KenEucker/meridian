#!/usr/bin/env python3
"""Validate Meridian pull request body process requirements."""

import argparse
import json
import os
import re
import sys
from pathlib import Path

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
REQUIREMENTS_DOCUMENT = REPOSITORY_ROOT / "docs/meridian-requirements-document.md"

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

REQUIREMENT_ID_RE = re.compile(
    r"\b([A-Z][A-Z0-9_]*-\d{3}[A-Z]?)\b",
    re.IGNORECASE,
)
REQUIREMENT_HEADING_RE = re.compile(
    r"^#{1,6}\s+([A-Z][A-Z0-9_]*-\d{3}[A-Z]?)\s*#*\s*$",
    re.MULTILINE,
)
# "Technical spec:" may reference one or more sections. Requirement IDs are
# loaded from the canonical requirements document below so new requirement
# families do not require this validator to be updated by hand.
TECHNICAL_SECTION_RE = re.compile(
    r"\bTechnical spec:\s*(?:[-*+]\s*)?Sections?\s*\d+(?:\.\d+)?",
    re.IGNORECASE,
)


def normalize_heading(text):
    return re.sub(r"\s+", " ", text.strip().lower())


def headings(body):
    found = set()
    for line in body.splitlines():
        match = re.match(r"^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$", line)
        if match:
            found.add(normalize_heading(match.group(1)))
    return found


def documented_requirement_ids():
    """Return requirement IDs declared in the canonical requirements document."""
    document = REQUIREMENTS_DOCUMENT.read_text(encoding="utf-8")
    return {
        match.group(1).upper()
        for match in REQUIREMENT_HEADING_RE.finditer(document)
    }


def has_traceability_reference(body, requirement_ids):
    referenced_ids = {
        match.group(1).upper() for match in REQUIREMENT_ID_RE.finditer(body)
    }
    return bool(referenced_ids & requirement_ids) or bool(
        TECHNICAL_SECTION_RE.search(body)
    )


def load_body(args):
    if args.body_file:
        return Path(args.body_file).read_text(encoding="utf-8")
    if args.body is not None:
        return args.body
    event_path = os.environ.get("GITHUB_EVENT_PATH")
    if event_path:
        data = json.loads(Path(event_path).read_text(encoding="utf-8"))
        return ((data.get("pull_request") or {}).get("body")) or ""
    return ""


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--body-file")
    parser.add_argument("--body")
    args = parser.parse_args()

    body = load_body(args)
    errors = []
    found_headings = headings(body)

    for section in REQUIRED_SECTIONS:
        if normalize_heading(section) not in found_headings:
            errors.append(
                f"Missing PR section '{section}'. Add a '# {section}' heading using .github/pull_request_template.md."
            )

    requirement_ids = documented_requirement_ids()
    if not has_traceability_reference(body, requirement_ids):
        errors.append(
            "Missing traceability reference. Add a documented Meridian requirement ID such as POL-028 or ORG-001, or a reference like 'Technical spec: Section 21.12'."
        )

    if errors:
        print("PR body validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    print("PR body validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
