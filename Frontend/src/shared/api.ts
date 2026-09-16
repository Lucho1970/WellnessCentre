export const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1').replace(/\/$/, '');
export async function apiRequest<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${apiBaseUrl}${path}`, init);
  const text = await response.text();
  let body;
  try { body = text ? JSON.parse(text) : null; } catch { /* Infrastructure failures may be HTML. */ }
  if (!response.ok || !body || typeof body !== 'object' || !('data' in body)) {
    const message = typeof body?.error?.message === 'string' ? body.error.message : `The service returned an unexpected response (${response.status}). Please try again.`;
    const reference = typeof body?.error?.correlation_id === 'string' ? ` Reference: ${body.error.correlation_id}` : '';
    throw new Error(message + reference);
  }
  return body.data as T;
}
