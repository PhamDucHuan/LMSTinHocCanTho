import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { compareFingerprints, createDocumentFingerprint } from '../lib/document-tools.js';

test('identical files are detected deterministically', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'lms-fingerprint-'));
  const first = path.join(directory, 'a.txt');
  const second = path.join(directory, 'b.txt');
  try {
    fs.writeFileSync(first, 'same content');
    fs.writeFileSync(second, 'same content');
    const document = { type: 'txt', text: 'same content' };
    const comparison = compareFingerprints(
      createDocumentFingerprint(first, document),
      createDocumentFingerprint(second, document),
    );
    assert.equal(comparison.identical_file_hash, true);
    assert.equal(comparison.suspicious_submission, true);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
