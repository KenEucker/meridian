#!/usr/bin/env python3
"""Validate Conventional Commit messages without third-party dependencies."""

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ALLOWED_TYPES = {
    "build",
    "chore",
    "ci",
    "docs",
    "feat",
    "fix",
    "perf",
    "refactor",
    "revert",
    "style",
    "test",
}

HEADER_RE = re.compile(
    r"^(?P<type>[a-z]+)(?:\([a-z0-9][a-z0-9._/-]*\))?(?P<breaking>!)?: (?P<subject>\S.+)$"
)

IGNORED_GENERATED_RE = re.compile(
    r"^(Merge pull request #\d+|Merge branch |Merge remote-tracking branch |Revert \")"
)


def first_line(message):
    return (message or "").strip().splitlines()[0].strip() if (message or "").strip() else ""


def validate_header(header, label):
    errors = []
    if not header:
        return [f"{label} is empty. Use a Conventional Commit header such as 'docs(process): add QA validator'."]

    if IGNORED_GENERATED_RE.match(header):
        return []

    match = HEADER_RE.match(header)
    if not match:
        return [
            f"{label} is not a Conventional Commit: '{header}'. Use 'type(scope): summary' or 'type: summary'."
        ]

    commit_type = match.group("type")
    if commit_type not in ALLOWED_TYPES:
        allowed = ", ".join(sorted(ALLOWED_TYPES))
        errors.append(f"{label} uses unsupported type '{commit_type}'. Allowed types: {allowed}.")

    subject = match.group("subject")
    if subject.endswith("."):
        errors.append(f"{label} subject should not end with a period: '{header}'.")
    if len(header) > 100:
        errors.append(f"{label} header is {len(header)} characters. Keep it at or under 100 characters.")

    return errors


def messages_from_event():
    event_path = os.environ.get("GITHUB_EVENT_PATH")
    if not event_path:
        return []

    data = json.loads(Path(event_path).read_text(encoding="utf-8"))
    if "pull_request" in data:
        return [("PR title", data["pull_request"].get("title", ""))]

    messages = []
    for index, commit in enumerate(data.get("commits", []), start=1):
        messages.append((f"Commit {index}", commit.get("message", "")))
    return messages


def messages_from_range(commit_range):
    result = subprocess.run(
        ["git", "log", "--format=%B%x00", commit_range],
        check=True,
        capture_output=True,
        text=True,
    )
    parts = [part.strip() for part in result.stdout.split("\x00") if part.strip()]
    return [(f"Commit {index}", message) for index, message in enumerate(parts, start=1)]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--message", action="append", help="Commit message text to validate.")
    parser.add_argument("--message-file", action="append", help="File containing a commit message.")
    parser.add_argument("--range", dest="commit_range", help="Git commit range to validate, such as origin/production..HEAD.")
    parser.add_argument("--event", action="store_true", help="Read PR title or pushed commits from GITHUB_EVENT_PATH.")
    args = parser.parse_args()

    messages = []
    for message in args.message or []:
        messages.append(("Message", message))
    for path in args.message_file or []:
        messages.append((path, Path(path).read_text(encoding="utf-8")))
    if args.commit_range:
        messages.extend(messages_from_range(args.commit_range))
    if args.event:
        messages.extend(messages_from_event())

    if not messages:
        print("Conventional Commit validation failed:")
        print("- No messages were provided. Pass --message, --message-file, --range, or --event.")
        return 1

    errors = []
    for label, message in messages:
        errors.extend(validate_header(first_line(message), label))

    if errors:
        print("Conventional Commit validation failed:")
        for error in errors:
            print(f"- {error}")
        print("- Example: docs(process): add conventional commit policy")
        return 1

    print("Conventional Commit validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
