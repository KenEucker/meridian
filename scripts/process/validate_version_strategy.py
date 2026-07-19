#!/usr/bin/env python3
"""Validate Meridian product versioning rules."""

import json
import re
import sys
from pathlib import Path

ROOT_PACKAGE_JSON = Path("package.json")
WORKSPACE_PACKAGE_GLOBS = ("apps/*/package.json", "packages/*/package.json")
NUMERIC_VERSION = re.compile(r"^\d+\.\d+\.\d+$")


def read_json(path):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        return f"{path} is not valid JSON: {exc}"


def main():
    errors = []

    root_package = read_json(ROOT_PACKAGE_JSON)
    if isinstance(root_package, str):
        errors.append(root_package)
    elif root_package.get("name") != "meridian":
        errors.append("Root package.json must be the Meridian package.")
    else:
        version = root_package.get("version")
        if not isinstance(version, str) or NUMERIC_VERSION.fullmatch(version) is None:
            errors.append("Root package.json version must use numeric x.y.z format with no suffix.")

    workspace_packages = []
    for pattern in WORKSPACE_PACKAGE_GLOBS:
        workspace_packages.extend(Path().glob(pattern))

    for path in sorted(workspace_packages):
        package_json = read_json(path)
        if isinstance(package_json, str):
            errors.append(package_json)
            continue

        if "version" in package_json:
            errors.append(f"{path} must omit version; root package.json is the product version source.")

    if errors:
        print("Version strategy validation failed:")
        for error in errors:
            print(f"- {error}")
        return 1

    print("Version strategy validation passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
