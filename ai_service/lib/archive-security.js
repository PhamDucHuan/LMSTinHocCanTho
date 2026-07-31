import path from 'node:path';

const blockedExtensions = new Set(['.exe', '.bat', '.cmd', '.ps1', '.php', '.js']);
const nestedArchiveExtensions = new Set(['.zip', '.rar', '.7z', '.tar', '.gz', '.bz2']);

export function safeArchiveName(name) {
  const normalized = String(name || '').replaceAll('\\', '/').replace(/^\/+|\/+$/g, '');
  const parts = normalized.split('/');
  if (!normalized || normalized.includes('\0') || normalized.includes(':')
      || parts.includes('..') || path.posix.isAbsolute(normalized)) {
    throw new Error(`Unsafe archive path: ${name}`);
  }
  return normalized;
}

export function validateArchiveEntries(entries, {
  maxFiles = 300,
  maxTotalBytes = 100 * 1024 * 1024,
  maxFileBytes = 25 * 1024 * 1024,
  maxCompressionRatio = 200,
} = {}) {
  if (entries.length > maxFiles) throw new Error(`Archive has more than ${maxFiles} entries`);
  let total = 0;
  return entries.map(entry => {
    const safeName = safeArchiveName(entry.name);
    const size = Math.max(0, Number(entry.size || 0));
    const compressedSize = Math.max(0, Number(entry.compressedSize || 0));
    if (!entry.isDirectory) {
      const normalizedBaseName = path.posix.basename(safeName).replace(/[.\s]+$/g, '');
      const extension = path.posix.extname(normalizedBaseName).toLowerCase();
      if (blockedExtensions.has(extension)) {
        throw new Error(`File nén chứa định dạng bị chặn (${extension}): ${safeName}`);
      }
      if (nestedArchiveExtensions.has(extension)) {
        throw new Error(`Không hỗ trợ file nén lồng nhau (${extension}): ${safeName}`);
      }
      if (size > 1024 * 1024 && compressedSize > 0 && size / compressedSize > maxCompressionRatio) {
        throw new Error(`Tỷ lệ nén bất thường, có nguy cơ archive bomb: ${safeName}`);
      }
    }
    if (size > maxFileBytes) throw new Error(`Archive entry is too large: ${safeName}`);
    total += size;
    if (total > maxTotalBytes) throw new Error('Archive expands beyond the safety limit');
    return { name: safeName, size, compressedSize, isDirectory: Boolean(entry.isDirectory) };
  });
}
