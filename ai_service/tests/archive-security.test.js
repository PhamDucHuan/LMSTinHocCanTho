import assert from 'node:assert/strict';
import test from 'node:test';
import { safeArchiveName, validateArchiveEntries } from '../lib/archive-security.js';

test('archive paths cannot escape the extraction root', () => {
  assert.throws(() => safeArchiveName('../config/.env'), /Unsafe archive path/);
  assert.throws(() => safeArchiveName('C:\\Windows\\file.txt'), /Unsafe archive path/);
});

test('dangerous executable and script files are blocked case-insensitively', () => {
  for (const name of ['run.EXE', 'start.bat', 'shell.php', 'task.PS1', 'code.js']) {
    assert.throws(
      () => validateArchiveEntries([{ name, size: 10, compressedSize: 10 }]),
      /định dạng bị chặn/,
    );
  }
});

test('nested archives are rejected', () => {
  assert.throws(
    () => validateArchiveEntries([{ name: 'inside/data.zip', size: 100, compressedSize: 80 }]),
    /nén lồng nhau/,
  );
});

test('suspicious compression ratios are rejected', () => {
  assert.throws(
    () => validateArchiveEntries([{
      name: 'huge.txt',
      size: 10 * 1024 * 1024,
      compressedSize: 1024,
    }]),
    /archive bomb/,
  );
});

test('normal Windows evidence files remain accepted', () => {
  assert.deepEqual(
    validateArchiveEntries([
      { name: 'BAILAM/Hinh-De33.JPG', size: 50000, compressedSize: 40000 },
      { name: 'BAILAM/ghichu.txt', size: 100, compressedSize: 90 },
    ]).map(entry => entry.name),
    ['BAILAM/Hinh-De33.JPG', 'BAILAM/ghichu.txt'],
  );
});
