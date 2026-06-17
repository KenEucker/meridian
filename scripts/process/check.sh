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
  corepack pnpm install --frozen-lockfile
  for script in lint test typecheck build; do
    if node -e "const scripts = require('./package.json').scripts || {}; process.exit(Object.prototype.hasOwnProperty.call(scripts, process.argv[1]) ? 0 : 1)" "$script"; then
      corepack pnpm run "$script"
    else
      echo "No pnpm script '$script' found; skipping."
    fi
  done
else
  echo "No package.json found; skipping Node checks."
fi
