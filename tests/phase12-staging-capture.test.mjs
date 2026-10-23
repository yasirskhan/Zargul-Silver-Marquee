import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

/** Test the private staging capture entrypoint without network/MFA secrets. */
test('Phase 12 private desktop/mobile staging capture script parses on pinned Node', () => {
  const source=fileURLToPath(new URL('../scripts/phase12-staging-browser-smoke.mjs',import.meta.url));
  const parsed=spawnSync(process.execPath,['--check',source],{encoding:'utf8',timeout:15000});
  assert.equal(parsed.status,0,parsed.stderr||parsed.error?.message);
});
