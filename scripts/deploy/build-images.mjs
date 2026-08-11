#!/usr/bin/env node
/**
 * Build the Meridian deployment images and tag them with the Meridian version.
 *
 * Technical spec 26.3 lists Docker image tags among the version metadata a
 * versioned Alpha 1 build carries, and the versioning strategy makes the root
 * `package.json` version the only source of truth for it. So the tag is read from
 * that manifest rather than passed in: a tag typed by hand is a tag that can
 * disagree with the version the server inside the image reports, and the
 * disagreement would not be visible until someone compared them.
 *
 * Usage:
 *   node scripts/deploy/build-images.mjs            build both images
 *   node scripts/deploy/build-images.mjs --print    print the tag and exit
 *   node scripts/deploy/build-images.mjs --target server
 *
 * The built images are what deploy/docker/compose.deployment.yaml runs. Set
 * MERIDIAN_IMAGE_TAG in the deployment env file to the value this prints.
 */

import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** The image name the deployment Compose file defaults to, without a tag. */
export const DEFAULT_IMAGE_NAME = 'meridian/server';

/** The Dockerfile targets the bundle ships, and the image suffix each produces. */
export const IMAGE_TARGETS = [
  { target: 'server', suffix: '' },
  { target: 'web', suffix: '-web' },
];

/**
 * The tag the deployment images carry: the root Meridian version, unmodified.
 */
export function meridianImageTag(root = repositoryRoot) {
  const manifest = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));

  return manifest.version;
}

function imageReference(target, tag, imageName) {
  const { suffix } = IMAGE_TARGETS.find((candidate) => candidate.target === target);

  return `${imageName}${suffix}:${tag}`;
}

function main() {
  const args = process.argv.slice(2);
  const tag = meridianImageTag();

  if (args.includes('--print')) {
    process.stdout.write(`${tag}\n`);

    return 0;
  }

  const imageName = process.env.MERIDIAN_IMAGE ?? DEFAULT_IMAGE_NAME;
  const requested = args.includes('--target') ? args[args.indexOf('--target') + 1] : null;
  const targets = requested
    ? IMAGE_TARGETS.filter((candidate) => candidate.target === requested)
    : IMAGE_TARGETS;

  if (targets.length === 0) {
    console.error(
      `Unknown target '${requested}'. Known targets: ${IMAGE_TARGETS.map((entry) => entry.target).join(', ')}.`,
    );

    return 1;
  }

  for (const { target } of targets) {
    const reference = imageReference(target, tag, imageName);

    console.log(`Building ${reference} (target ${target}).`);

    const result = spawnSync(
      'docker',
      [
        'build',
        '--file',
        join('deploy', 'docker', 'Dockerfile'),
        '--target',
        target,
        '--tag',
        reference,
        '.',
      ],
      { cwd: repositoryRoot, stdio: 'inherit' },
    );

    if (result.status !== 0) {
      console.error(`Building ${reference} failed.`);

      return result.status ?? 1;
    }
  }

  console.log(`\nBuilt ${targets.length} image(s) tagged ${tag}.`);
  console.log(`Set MERIDIAN_IMAGE_TAG=${tag} in the deployment environment file.`);

  return 0;
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  process.exit(main());
}
