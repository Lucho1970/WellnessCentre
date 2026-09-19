import i18n from '../i18n';

export const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '');

type ErrorBody = { error?: { code?: unknown; message?: unknown; correlation_id?: unknown } } | null | undefined;

const numericIdKey = /(^id$|_id$|_ids$)/;
const unsignedInteger = /^(0|[1-9]\d*)$/;
const stringIdentifierKeys = new Set(['object_id', 'tenant_id', 'correlation_id', 'external_id', 'source_event_id', 'home_account_id']);

/**
 * PDO may serialize integer database IDs as JSON strings. Normalize only
 * numeric values in ID-shaped fields; UUIDs and external identity subjects
 * remain strings.
 */
export function normalizeNumericIds<T>(value: T, idContext = false): T {
  if (Array.isArray(value)) return value.map(item => normalizeNumericIds(item, idContext)) as T;
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, child]) => [
      key,
      normalizeNumericIds(child, !stringIdentifierKeys.has(key) && numericIdKey.test(key)),
    ])) as T;
  }
  if (idContext && typeof value === 'string' && unsignedInteger.test(value)) {
    const number = Number(value);
    if (Number.isSafeInteger(number)) return number as T;
  }
  return value;
}

export function apiErrorMessage(body: ErrorBody, status: number, fallback?: string): string {
  const rawMessage = typeof body?.error?.message === 'string' ? body.error.message : '';
  const code = typeof body?.error?.code === 'string' ? body.error.code : '';
  let message = '';
  if (rawMessage && i18n.exists(rawMessage)) message = i18n.t(rawMessage);
  if (!message && code && i18n.exists(`API error: ${code}`)) message = i18n.t(`API error: ${code}`);
  if (!message) {
    const statusKey = status === 401 ? 'API error: unauthorized'
      : status === 403 ? 'API error: forbidden'
        : status === 404 ? 'API error: not found'
          : status === 409 ? 'API error: conflict'
            : status === 422 ? 'API error: validation'
              : status === 429 ? 'API error: rate limited'
                : status >= 500 ? 'API error: server'
                  : 'API error: request failed';
    message = fallback || i18n.t(statusKey, { status });
  }
  const reference = typeof body?.error?.correlation_id === 'string'
    ? ` ${i18n.t('Reference: {{reference}}', { reference: body.error.correlation_id })}`
    : '';
  return message + reference;
}

export async function apiRequest<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${apiBaseUrl}${path}`, init);
  const text = await response.text();
  let body;
  try { body = text ? JSON.parse(text) : null; } catch { /* Infrastructure failures may be HTML. */ }
  if (!response.ok || !body || typeof body !== 'object' || !('data' in body)) {
    throw new Error(apiErrorMessage(body, response.status));
  }
  return normalizeNumericIds(body.data as T);
}
