#!/usr/bin/env node
// Checks a package directory (this directory after `pnpm build`, or an extracted tarball's `package/`):
//   1. every `exports` target exists
//   2. the main entry (`.`) and the agents entry (`./agents`) do not reach the screen (no React component code) and
//      `./agents` loads `@mgamadeus/ddd-agents` for types only (nothing at runtime)
//   3. every JavaScript file starts with the 'use client' directive
//   4. no test file is in `dist`
//
// Usage: node scripts/check-package.mjs <packageDir>
import * as fs from 'node:fs';
import * as path from 'node:path';

const packageDir = path.resolve(process.argv[2] ?? '.');
const manifest = JSON.parse(fs.readFileSync(path.join(packageDir, 'package.json'), 'utf-8'));
const problems = [];

/** Every string leaf of an `exports` value (conditions and subpaths flattened). */
const targets = value =>
  typeof value === 'string' ? [value] : value && typeof value === 'object' ? Object.values(value).flatMap(targets) : [];

for (const target of targets(manifest.exports ?? {})) {
  const exists = fs.existsSync(path.join(packageDir, target));
  console.log(`${exists ? 'ok     ' : 'MISSING'} exports ${target}`);
  if (!exists) problems.push(`missing exports target ${target}`);
}

const IMPORT_PATTERN = /(?:^|[\s;])(?:import|export)\s*(?:[^'"()]*?\sfrom\s*)?['"]([^'"]+)['"]/g;

/** Specifiers of the static imports and re-exports of a built file. */
const staticSpecifiers = file => [...fs.readFileSync(file, 'utf-8').matchAll(IMPORT_PATTERN)].map(match => match[1]);

/** Everything a built entry loads statically: its own files (followed) and the package specifiers it names. */
const loadGraph = entry => {
  const files = new Set();
  const packages = new Set();
  const queue = [path.join(packageDir, entry)];
  while (queue.length > 0) {
    const file = queue.pop();
    if (files.has(file) || !fs.existsSync(file)) continue;
    files.add(file);
    for (const specifier of staticSpecifiers(file)) {
      if (specifier.startsWith('.')) queue.push(path.resolve(path.dirname(file), specifier));
      else packages.add(specifier);
    }
  }
  return { files, packages };
};

for (const entryName of ['.', './agents']) {
  const entry = manifest.exports?.[entryName]?.import;
  if (!entry || !fs.existsSync(path.join(packageDir, entry))) continue;
  const graph = loadGraph(entry);
  const screenPackages = [...graph.packages].filter(
    specifier => specifier === 'react' || specifier === 'lucide-react' || specifier.startsWith('@mgamadeus/ddd-admin-ui/files') || specifier.startsWith('@mgamadeus/ddd-agents')
  );
  console.log(`${screenPackages.length === 0 ? 'ok     ' : 'FAILED '} ${entryName} loads no screen code and no ddd-agents at runtime`);
  if (screenPackages.length > 0) problems.push(`${entryName} loads ${screenPackages.join(', ')}`);
}

const distDir = path.join(packageDir, 'dist');
if (fs.existsSync(distDir)) {
  const distFiles = fs.readdirSync(distDir, { recursive: true }).map(String);
  const withoutDirective = distFiles.filter(
    file => file.endsWith('.js') && !/^['"]use client['"];/.test(fs.readFileSync(path.join(distDir, file), 'utf-8'))
  );
  console.log(`${withoutDirective.length === 0 ? 'ok     ' : 'FAILED '} every .js file starts with 'use client'`);
  if (withoutDirective.length > 0) problems.push(`no 'use client' in ${withoutDirective.join(', ')}`);
  const testFiles = distFiles.filter(file => /\.test\./.test(file));
  console.log(`${testFiles.length === 0 ? 'ok     ' : 'FAILED '} no test files in dist`);
  if (testFiles.length > 0) problems.push(`test files in dist: ${testFiles.join(', ')}`);
}

if (problems.length > 0) {
  for (const problem of problems) console.error(`${packageDir}: ${problem}`);
  process.exit(1);
}
