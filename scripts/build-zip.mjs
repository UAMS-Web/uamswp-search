#!/usr/bin/env node
// Build the upload ZIP from the download keep-list in .gitattributes.
//
// The ZIP is `git archive` of a ref with the keep-list applied, so it holds exactly
// what a branch download holds: only what the plugin runs. Nothing is committed; the
// ZIP and its checksum land in build/ (ignored) and the release workflow attaches
// them to a GitHub release.
//
// Usage: node scripts/build-zip.mjs [ref] [--version <version>]
//   ref        the commit-ish to archive (default HEAD)
//   --version  the version in the file name (default: the Version header of the main file)

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const slug = 'uams-search';

const args = process.argv.slice(2);
const versionFlag = args.indexOf('--version');
const version = versionFlag >= 0 ? args.splice(versionFlag, 2)[1] : null;
const ref = args[0] ?? 'HEAD';

function headerVersion() {
  const source = readFileSync(join(root, `${slug}.php`), 'utf8');
  const match = source.match(/^\s*\*\s*Version:\s*(\S+)/m);

  if (!match) {
    throw new Error(`No Version header in ${slug}.php`);
  }

  return match[1];
}

const out = join(root, 'build');
mkdirSync(out, { recursive: true });

const file = join(out, `${slug}-${version ?? headerVersion()}.zip`);

execFileSync(
  'git',
  ['-C', root, 'archive', '--worktree-attributes', '--format=zip', `--prefix=${slug}/`, '-o', file, ref],
  { stdio: 'inherit' },
);

const digest = createHash('sha256').update(readFileSync(file)).digest('hex');
writeFileSync(`${file}.sha256`, `${digest}  ${basename(file)}\n`);

console.log(`Built ${file}`);
console.log(`SHA256 ${digest}`);
