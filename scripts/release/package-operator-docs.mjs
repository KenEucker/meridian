#!/usr/bin/env node
/**
 * Package the operator documentation tree with the server deployment.
 *
 * The God Mode Documentation page renders documentation packaged with the
 * deployment and must not need network access (GOD-014). The repository tree at
 * `docs/operator/` is the source; this copies it into the server's resources so
 * a shipped server carries its own documentation.
 *
 * Only `docs/operator/` is copied. The requirements document, technical
 * specification, data/API specification, UI documentation, QA scripts,
 * architecture decision records, development plan, traceability matrix, and
 * issue documents are deliberately not packaged and are therefore not reachable
 * from the console (GOD-015).
 *
 * The manifest records the Meridian version the documentation was packaged
 * from, which the page shows beside the running build version so an operator
 * can tell whether the two match (GOD-017). It carries no timestamp: the output
 * is committed, and a regenerated-at field would make every run a diff.
 *
 * Usage: node scripts/release/package-operator-docs.mjs [--check]
 */

import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const sourceDirectory = join(repositoryRoot, 'docs', 'operator');
const targetDirectory = join(repositoryRoot, 'apps', 'server', 'resources', 'operator-docs');
const indexFile = 'README.md';
const checkOnly = process.argv.includes('--check');

function meridianVersion() {
  const manifest = JSON.parse(readFileSync(join(repositoryRoot, 'package.json'), 'utf8'));

  return manifest.version;
}

function slugFor(file) {
  return file === indexFile ? 'index' : file.replace(/\.md$/i, '').toLowerCase();
}

function titleFor(markdown, file) {
  const heading = markdown.split(/\r?\n/).find((line) => /^#\s+\S/.test(line));

  return heading ? heading.replace(/^#\s+/, '').trim() : file;
}

/**
 * Every heading in the document, so the console can filter by heading as well
 * as by title (GOD-016).
 */
function headingsFor(markdown) {
  return markdown
    .split(/\r?\n/)
    .filter((line) => /^#{1,6}\s+\S/.test(line))
    .map((line) => line.replace(/^#{1,6}\s+/, '').trim());
}

/**
 * Reading order comes from the index document's own links, so the table an
 * operator reads and the order the console lists are the same thing. Anything
 * the index does not link to follows, alphabetically, rather than going missing.
 */
function orderedFiles(files) {
  const index = readFileSync(join(sourceDirectory, indexFile), 'utf8');
  const linked = [...index.matchAll(/\]\(([^)]+\.md)\)/g)].map((match) => match[1]);

  const ordered = [indexFile];

  for (const file of linked) {
    if (files.includes(file) && !ordered.includes(file)) {
      ordered.push(file);
    }
  }

  for (const file of files.slice().sort()) {
    if (!ordered.includes(file)) {
      ordered.push(file);
    }
  }

  return ordered;
}

function build() {
  if (!existsSync(sourceDirectory)) {
    throw new Error(`Operator documentation source not found at ${sourceDirectory}.`);
  }

  const files = readdirSync(sourceDirectory).filter((file) => /\.md$/i.test(file));

  if (!files.includes(indexFile)) {
    throw new Error(`docs/operator/${indexFile} is required as the documentation index.`);
  }

  const documents = [];
  const contents = new Map();

  for (const file of orderedFiles(files)) {
    const markdown = readFileSync(join(sourceDirectory, file), 'utf8');

    contents.set(file, markdown);
    documents.push({
      slug: slugFor(file),
      file,
      title: titleFor(markdown, file),
      headings: headingsFor(markdown),
    });
  }

  return {
    manifest: { version: meridianVersion(), documents },
    contents,
  };
}

const { manifest, contents } = build();
const manifestJson = `${JSON.stringify(manifest, null, 2)}\n`;
const manifestPath = join(targetDirectory, 'manifest.json');

if (checkOnly) {
  const problems = [];

  if (!existsSync(manifestPath) || readFileSync(manifestPath, 'utf8') !== manifestJson) {
    problems.push('apps/server/resources/operator-docs/manifest.json is out of date.');
  }

  for (const [file, markdown] of contents) {
    const packaged = join(targetDirectory, file);

    if (!existsSync(packaged) || readFileSync(packaged, 'utf8') !== markdown) {
      problems.push(`apps/server/resources/operator-docs/${file} is out of date.`);
    }
  }

  if (problems.length > 0) {
    console.error('Packaged operator documentation is stale:');
    for (const problem of problems) {
      console.error(`- ${problem}`);
    }
    console.error('Run: corepack pnpm run docs:package');
    process.exit(1);
  }

  console.log('Packaged operator documentation is current.');
  process.exit(0);
}

rmSync(targetDirectory, { recursive: true, force: true });
mkdirSync(targetDirectory, { recursive: true });

for (const [file, markdown] of contents) {
  writeFileSync(join(targetDirectory, file), markdown, 'utf8');
}

writeFileSync(manifestPath, manifestJson, 'utf8');

console.log(
  `Packaged ${manifest.documents.length} operator document(s) at version ${manifest.version} into apps/server/resources/operator-docs.`,
);
