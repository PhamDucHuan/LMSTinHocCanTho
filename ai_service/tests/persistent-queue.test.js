import assert from 'node:assert/strict';
import test from 'node:test';
import { shouldRetryGradingError } from '../lib/persistent-queue.js';

test('does not retry a grading job when Gemini quota is exhausted', () => {
  assert.equal(
    shouldRetryGradingError(new Error('Các tổ hợp API key/model Gemini khả dụng đã hết hạn mức.'), 1, 3),
    false,
  );
});

test('retries a transient error while attempts remain', () => {
  assert.equal(shouldRetryGradingError(new Error('network timeout'), 1, 3), true);
  assert.equal(shouldRetryGradingError(new Error('network timeout'), 3, 3), false);
});
