#!/usr/bin/env node
/**
 * Generate the packaged changelog data file from repository history.
 *
 * A release build runs this so the God Mode Changelog page renders completely
 * without network access (GOD-021). The generated file is the baseline; the
 * central node may later merge newer entries into it from the source repository
 * (GOD-022), but the baseline alone is always enough to render the page.
 *
 * Entries carry pull request title, body, number, merge date, and author, and
 * are grouped under the Meridian version each change shipped in (GOD-019).
 *
 * Every merged pull request appears. Nothing is filtered by conventional-commit
 * type or change category (GOD-020) — a release note that quietly drops the
 * chore and fix commits is how an operator ends up unable to explain what
 * changed on a node.
 *
 * Usage:
 *   node scripts/release/generate-changelog.mjs [--ref <git-ref>] [--out <path>]
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function argument(name, fallback) {
  const index = process.argv.indexOf(name);

  return index === -1 || index === process.argv.length - 1 ? fallback : process.argv[index + 1];
}

const ref = argument('--ref', 'HEAD');
const outputPath = resolve(
  repositoryRoot,
  argument('--out', join('apps', 'server', 'resources', 'changelog', 'changelog.json')),
);

const RECORD = '<<<MERIDIAN-RECORD>>>';
const FIELD = '<<<MERIDIAN-FIELD>>>';

function git(args, { quiet = false } = {}) {
  return execFileSync('git', args, {
    cwd: repositoryRoot,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
    stdio: quiet ? ['ignore', 'pipe', 'ignore'] : ['ignore', 'pipe', 'inherit'],
  });
}

/**
 * First-parent history is the production timeline: one entry per thing that
 * landed, rather than every commit that was ever on a branch.
 */
function commits() {
  const output = git([
    'log',
    '--first-parent',
    '--reverse',
    `--format=%H${FIELD}%an${FIELD}%aI${FIELD}%s${FIELD}%b${RECORD}`,
    ref,
  ]);

  return output
    .split(RECORD)
    .map((record) => record.replace(/^\n/, ''))
    .filter((record) => record.trim() !== '')
    .map((record) => {
      const [sha, author, date, subject, body = ''] = record.split(FIELD);

      return { sha, author, date, subject, body: body.trim() };
    });
}

/**
 * The root package.json version is the only Meridian product version source of
 * truth, so the version a change shipped in is read from history rather than
 * inferred from tags.
 */
function versionAt(sha) {
  // The earliest commits predate the root package.json, so a miss here is
  // expected rather than exceptional and git's own complaint is suppressed.
  try {
    return JSON.parse(git(['show', `${sha}:package.json`], { quiet: true })).version ?? null;
  } catch {
    return null;
  }
}

/**
 * Pull request number, from either merge style: a merge commit created by the
 * GitHub merge button, or a squashed commit carrying "(#123)".
 */
function pullRequestNumber(commit) {
  const merge = commit.subject.match(/^Merge pull request #(\d+)\b/);

  if (merge) {
    return Number(merge[1]);
  }

  const squashed = commit.subject.match(/\(#(\d+)\)\s*$/);

  return squashed ? Number(squashed[1]) : null;
}

/**
 * A merge commit's subject is "Merge pull request #N from branch", which says
 * nothing about the change. Its body is the merged head commit's message, which
 * is the closest thing to the pull request title and body that repository
 * history holds. A refresh from the source repository replaces both with the
 * real values (GOD-022).
 */
function titleAndBody(commit) {
  if (!/^Merge pull request #\d+\b/.test(commit.subject)) {
    return { title: commit.subject, body: commit.body };
  }

  const [first = '', ...rest] = commit.body.split(/\r?\n/);
  const title = first.trim();

  return {
    title: title === '' ? commit.subject : title,
    body: rest.join('\n').trim(),
  };
}

function compareVersionsDescending(a, b) {
  const left = a.split('.').map(Number);
  const right = b.split('.').map(Number);

  for (let index = 0; index < Math.max(left.length, right.length); index += 1) {
    const difference = (right[index] ?? 0) - (left[index] ?? 0);

    if (difference !== 0) {
      return difference;
    }
  }

  return 0;
}

function build() {
  const history = commits();

  // Versions only change where package.json changed, so the version at every
  // other commit is carried forward rather than shelled out for.
  const versionChanges = new Set(
    git(['log', '--first-parent', '--format=%H', ref, '--', 'package.json'])
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean),
  );

  let carried = null;

  for (const commit of history) {
    if (versionChanges.has(commit.sha) || carried === null) {
      carried = versionAt(commit.sha) ?? carried;
    }

    commit.version = carried;
  }

  const currentVersion = history.length === 0 ? null : history[history.length - 1].version;

  /** @type {Map<string, Array<object>>} */
  const byVersion = new Map();

  history.forEach((commit, index) => {
    const number = pullRequestNumber(commit);

    if (number === null) {
      return;
    }

    // A pull request ships in the version the bump that follows it produced.
    // While no bump has followed yet, it is in the version now running.
    let shipped = currentVersion;

    for (let next = index + 1; next < history.length; next += 1) {
      if (history[next].version !== commit.version) {
        shipped = history[next].version;
        break;
      }
    }

    if (shipped === null) {
      return;
    }

    const { title, body } = titleAndBody(commit);

    if (!byVersion.has(shipped)) {
      byVersion.set(shipped, []);
    }

    byVersion.get(shipped).push({
      number,
      title,
      body,
      author: commit.author,
      merged_at: commit.date,
    });
  });

  const releases = [...byVersion.entries()]
    .sort(([a], [b]) => compareVersionsDescending(a, b))
    .map(([version, entries]) => ({
      version,
      entries: entries.sort((a, b) => b.number - a.number),
    }));

  return {
    version: currentVersion,
    source_ref: ref,
    releases,
  };
}

const changelog = build();

mkdirSync(dirname(outputPath), { recursive: true });
writeFileSync(outputPath, `${JSON.stringify(changelog, null, 2)}\n`, 'utf8');

const entryCount = changelog.releases.reduce((total, release) => total + release.entries.length, 0);

console.log(
  `Generated ${entryCount} changelog entr${entryCount === 1 ? 'y' : 'ies'} across ${changelog.releases.length} version(s) into ${outputPath}.`,
);
