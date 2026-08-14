#!/usr/bin/env node
/**
 * Shared `.env` loading for local release builds (M19.22 / spec 26.5).
 *
 * CI supplies the release signing credentials from repository secrets; a local
 * release reads them from `.env` at the repository root, which is gitignored,
 * so the release maintainer keeps them out of both the commit history and the
 * shell profile. Values already exported in the environment win over `.env`
 * lines, so a CI-style invocation still behaves the same with the file
 * present.
 *
 * Nothing here defaults a credential. The file is a source of values, never a
 * source of fallbacks: a variable absent from both the environment and the
 * file stays absent, so each platform's own guard still refuses the build
 * rather than falling back to automatic or debug signing.
 */

import { existsSync, readFileSync } from 'node:fs';

/**
 * Parse `KEY=value` lines, tolerating a leading `export ` and a surrounding
 * pair of matching quotes. Blank lines and `#` comments are skipped; a line
 * with no `=`, or with an empty key, is ignored rather than failing the run.
 */
export function parseEnvFile(text) {
  const values = {};
  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) {
      continue;
    }
    const assignment = line.startsWith('export ') ? line.slice('export '.length).trim() : line;
    const separator = assignment.indexOf('=');
    if (separator < 1) {
      continue;
    }
    const key = assignment.slice(0, separator).trim();
    let value = assignment.slice(separator + 1).trim();
    const quoted =
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"));
    if (quoted && value.length >= 2) {
      value = value.slice(1, -1);
    }
    values[key] = value;
  }
  return values;
}

/**
 * `process.env` overlaid with any `.env` values it does not already carry.
 * A missing file is not an error: the environment alone is a valid supply.
 */
export function loadReleaseEnv(envFilePath, environment = process.env) {
  const env = { ...environment };
  if (!existsSync(envFilePath)) {
    return env;
  }
  for (const [key, value] of Object.entries(parseEnvFile(readFileSync(envFilePath, 'utf8')))) {
    if (env[key] === undefined || env[key] === '') {
      env[key] = value;
    }
  }
  return env;
}

/** The names from `required` that `env` carries no non-empty value for. */
export function missingCredentials(env, required) {
  return required.filter((name) => !env[name]);
}
