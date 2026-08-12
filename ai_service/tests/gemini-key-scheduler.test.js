import assert from 'node:assert/strict';
import test from 'node:test';
import { GeminiKeyScheduler } from '../lib/gemini-key-scheduler.js';

test('assigns concurrent requests to currently unused key slots first', () => {
  const scheduler = new GeminiKeyScheduler();
  assert.deepEqual(scheduler.rank(3), [0, 1, 2]);
  const releasePrimary = scheduler.reserve(0);
  assert.deepEqual(scheduler.rank(3), [1, 2, 0]);
  const releaseBackup = scheduler.reserve(1);
  assert.deepEqual(scheduler.rank(3), [2, 0, 1]);
  releasePrimary();
  assert.deepEqual(scheduler.rank(3), [0, 2, 1]);
  releaseBackup();
  assert.deepEqual(scheduler.rank(3), [0, 1, 2]);
});
