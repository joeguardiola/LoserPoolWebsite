// node --test cron/worker.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dispatchReminder } from './worker.js';

test('dispatches the remind workflow for real, not as a dry run', async () => {
  let seen;
  await dispatchReminder('t0ken', async (url, init) => {
    seen = { url, init };
    return new Response(null, { status: 204 });
  });

  assert.equal(
    seen.url,
    'https://api.github.com/repos/joeguardiola/LoserPoolWebsite/actions/workflows/remind.yml/dispatches',
  );
  assert.equal(seen.init.method, 'POST');
  assert.equal(seen.init.headers.Authorization, 'Bearer t0ken');
  assert.deepEqual(JSON.parse(seen.init.body), { ref: 'master', inputs: { dry_run: 'false' } });
});

test('throws when GitHub refuses, so the failure is logged', async () => {
  await assert.rejects(
    dispatchReminder('bad', async () => new Response('Bad credentials', { status: 401 })),
    /401 Bad credentials/,
  );
});
