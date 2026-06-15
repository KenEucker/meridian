#!/usr/bin/env sh
set -eu

if [ -n "${PYTHON_BIN:-}" ]; then
  PYTHON="$PYTHON_BIN"
elif command -v python3 >/dev/null 2>&1; then
  PYTHON="python3"
elif command -v python >/dev/null 2>&1; then
  PYTHON="python"
elif command -v py >/dev/null 2>&1; then
  PYTHON="py -3"
else
  echo "Python was not found. Install Python 3 or rerun with PYTHON_BIN=/path/to/python."
  exit 1
fi

$PYTHON scripts/process/validate_traceability_matrix.py
$PYTHON scripts/process/validate_qa_docs.py
$PYTHON scripts/process/validate_repo_process.py
$PYTHON scripts/process/validate_conventional_commits.py --message "docs(process): validate process scaffold"

if [ -f composer.json ]; then
  composer validate --no-check-publish
else
  echo "No composer.json found; skipping Composer checks."
fi

if [ -f package.json ]; then
  npm ci
  for script in lint test typecheck build; do
    if npm run | grep -E "^[[:space:]]+$script$" >/dev/null 2>&1; then
      npm run "$script"
    else
      echo "No npm script '$script' found; skipping."
    fi
  done
else
  echo "No package.json found; skipping Node checks."
fi
