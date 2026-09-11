/**
 * Client-side mirror of Laravel's `email` (RFC) rule so the form catches what the API would reject with 422:
 * exactly one "@", no whitespace, no consecutive/leading/trailing dots, a dotted domain.
 */
export const isValidEmail = (value: string): boolean => {
  const v = value.trim();
  if (!v || /\s/.test(v)) return false;
  const parts = v.split('@');
  if (parts.length !== 2) return false;
  const [local, domain] = parts;
  if (!local || local.length > 64 || local.startsWith('.') || local.endsWith('.') || local.includes('..')) return false;
  if (!domain || domain.startsWith('.') || domain.endsWith('.') || domain.includes('..') || domain.startsWith('-') || domain.endsWith('-')) return false;
  return /^[A-Za-z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test(local) && /^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*$/.test(domain);
};
