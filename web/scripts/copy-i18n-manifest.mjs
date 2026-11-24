#!/usr/bin/env node
// Copies the text manifest and the translator hints into dist after the build, so the packed package ships
// dist/i18n-keys.json (export `@mgamadeus/ddd-clouddrive/i18n-keys.json`) and dist/i18n/hints.json.
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageDir = join(dirname(fileURLToPath(import.meta.url)), '..');
mkdirSync(join(packageDir, 'dist', 'i18n'), { recursive: true });
copyFileSync(join(packageDir, 'i18n-keys.json'), join(packageDir, 'dist', 'i18n-keys.json'));
copyFileSync(join(packageDir, 'i18n', 'hints.json'), join(packageDir, 'dist', 'i18n', 'hints.json'));
console.log('copy-i18n-manifest: dist/i18n-keys.json, dist/i18n/hints.json');
