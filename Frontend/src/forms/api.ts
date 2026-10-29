import { useCallback } from 'react';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds, validationFields } from '../shared/api';
export type FormRequest = <T = unknown>(path: string, init?: RequestInit) => Promise<T>;
export class FormRequestError extends Error { readonly fields: Record<string, string>; constructor(message: string, readonly status: number, fields?: unknown) { super(message); this.fields = validationFields(fields); } }
export function useFormRequest(): FormRequest {
  const { getAccessToken } = useStaffAuth();
  return useCallback(async <T,>(path: string, init: RequestInit = {}): Promise<T> => {
    const token = await getAccessToken();
    const response = await fetch(`${apiBaseUrl}${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const body = await response.json();
    if (!response.ok) throw new FormRequestError(apiErrorMessage(body, response.status), response.status, body?.error?.fields);
    if (!body?.data) throw new Error('Invalid form response.');
    return normalizeNumericIds(body.data as T);
  }, [getAccessToken]);
}
