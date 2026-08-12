import test from 'node:test';
import assert from 'node:assert/strict';
import { configuredGeminiApiKeys } from '../lib/gemini-config.js';

test('multiple Gemini keys are normalized and legacy key remains a fallback', () => {
  assert.deepEqual(configuredGeminiApiKeys({
    GEMINI_API_KEYS: ' key-one, key-two;key-one\nkey-three ',
    GEMINI_API_KEY: 'legacy-key',
  }), ['key-one', 'key-two', 'key-three', 'legacy-key']);
});

test('legacy Gemini key still works by itself', () => {
  assert.deepEqual(configuredGeminiApiKeys({ GEMINI_API_KEY: 'legacy-key' }), ['legacy-key']);
});

test('numbered backup slots are included in their configured order and ignore blanks', () => {
  assert.deepEqual(configuredGeminiApiKeys({
    GEMINI_API_KEY: 'primary',
    GEMINI_API_KEY_BACKUP_10: 'tenth',
    GEMINI_API_KEY_BACKUP_02: 'second',
    GEMINI_API_KEY_BACKUP_01: '',
  }), ['primary', 'second', 'tenth']);
});
