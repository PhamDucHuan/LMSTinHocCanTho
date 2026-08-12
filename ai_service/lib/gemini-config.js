export function configuredGeminiApiKeys(environment = process.env) {
  const multiple = String(environment.GEMINI_API_KEYS || '')
    .split(/[\r\n,;]+/)
    .map(value => value.trim())
    .filter(Boolean);
  const legacy = String(environment.GEMINI_API_KEY || '').trim();
  const backupSlots = Object.entries(environment)
    .filter(([name]) => /^GEMINI_API_KEY_BACKUP_\d{2}$/.test(name))
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([, value]) => String(value || '').trim())
    .filter(Boolean);
  return [...new Set([...multiple, legacy, ...backupSlots].filter(Boolean))];
}
