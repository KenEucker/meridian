import fs from 'node:fs';
import path from 'node:path';

const repoRoot = process.cwd();
const logPath = path.join(repoRoot, 'apps/server/storage/logs/laravel.log');
const relativeLogPath = path.relative(repoRoot, logPath);
const filterIndex = process.argv.indexOf('--filter');
const filter = filterIndex >= 0 ? process.argv[filterIndex + 1] : null;
const tailLines = 80;

function printLine(line) {
  const normalizedLine = line.replaceAll('&amp;', '&');

  if (filter !== null && filter !== undefined && !normalizedLine.includes(filter)) {
    return;
  }

  const jsonUrlMatch = normalizedLine.match(/"url":"((?:\\.|[^"\\])*)"/);

  if (jsonUrlMatch) {
    console.log(jsonUrlMatch[1].replaceAll('\\/', '/'));
    return;
  }

  const plainUrlMatch = normalizedLine.match(
    /(https?:\/\/\S+\/login\/magic-link\/verify\?\S+)/,
  );

  if (plainUrlMatch) {
    console.log(plainUrlMatch[1]);
    return;
  }

  console.log(normalizedLine);
}

function printRecentLines() {
  const content = fs.readFileSync(logPath, 'utf8');
  const lines = content.split('\n').filter(Boolean);

  for (const line of lines.slice(-tailLines)) {
    printLine(line);
  }
}

function followLog() {
  let position = fs.statSync(logPath).size;

  fs.watchFile(logPath, { interval: 250 }, () => {
    const { size } = fs.statSync(logPath);

    if (size < position) {
      position = 0;
    }

    if (size <= position) {
      return;
    }

    const buffer = Buffer.alloc(size - position);
    const fileDescriptor = fs.openSync(logPath, 'r');

    fs.readSync(fileDescriptor, buffer, 0, buffer.length, position);
    fs.closeSync(fileDescriptor);

    position = size;

    for (const line of buffer.toString('utf8').split('\n')) {
      if (line) {
        printLine(line);
      }
    }
  });
}

function startFollowing() {
  console.log(`Following ${relativeLogPath} (Ctrl+C to stop)`);

  if (filter) {
    console.log(`Filter: ${filter}`);
  } else {
    console.log('Tip: use --filter login/magic-link/verify to show magic login links only');
  }

  printRecentLines();
  followLog();
}

if (!fs.existsSync(logPath)) {
  fs.mkdirSync(path.dirname(logPath), { recursive: true });
  console.log(`Waiting for ${relativeLogPath}...`);
  console.log('Start the server with pnpm run server:dev, then request a magic link at /login.');

  fs.watchFile(logPath, { interval: 500 }, (current, previous) => {
    if (previous.size === 0 && current.size > 0 && fs.existsSync(logPath)) {
      fs.unwatchFile(logPath);
      startFollowing();
    }
  });
} else {
  startFollowing();
}
