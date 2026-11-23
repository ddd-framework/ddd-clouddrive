// Runtime import of the client and agents entries in Node. The screen entry is React component code for a bundler;
// its file and imports are checked by scripts/check-package.mjs and the type check of consumer.ts.
const main = await import('@mgamadeus/ddd-clouddrive');
const agents = await import('@mgamadeus/ddd-clouddrive/agents');

const expectations = [
  [main, 'main', ['createCloudDriveApi', 'createStaticCloudDriveApi', 'buildCloudDriveRequests', 'cloudDriveEndpointDefinitions', 'CLOUD_DRIVE_TAG']],
  [agents, 'agents', ['createCloudDriveCardsSource', 'buildDriveScreenHref', 'nodeIdOfDriveReference']],
];
for (const [module, name, expected] of expectations) {
  const missing = expected.filter(exportName => !(exportName in module));
  if (missing.length > 0) {
    console.error(`${name} entry misses exports: ${missing.join(', ')}`);
    process.exit(1);
  }
  console.log(`ok      ${name} entry exports ${Object.keys(module).length} names`);
}
if ('CloudDriveScreen' in main) {
  console.error('the main entry must not export the screen');
  process.exit(1);
}
