#!/usr/bin/env python3
"""Validate Meridian pull request body process requirements."""

import argparse
import json
import os
import re
import sys
from pathlib import Path

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

TRACE_RE = re.compile(
    r"\b(?:ORG|VOL|TEAM|SLB|FR|INC|SHIFT|APP|STAT|TRAIN|WAIVER|CRED|HOURS|CREDIT|EQUIP|REPORT)-\d{3}\b"
    # "Technical spec:" label followed by a "Section X(.Y)" reference. The
    # reference may sit on the same line or on a following (optionally
    # bulleted) line, matching the canonical Traceability format in
    # docs/process/meridian-development-process.md section 5.1.
    r"|Technical spec:\s*(?:[-*+]\s*)?Section\s*\d+(?:\.\d+)?",
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

    if not TRACE_RE.search(body):
        errors.append(
            "Missing traceability reference. Add a Meridian requirement ID such as ORG-001, SHIFT-016, FR-012, INC-014, or a reference like 'Technical spec: Section 28'."
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
