import { useCallback } from 'react';
import { useStaffAuth } from '../auth/AuthProvider';
import { apiBaseUrl, apiErrorMessage, normalizeNumericIds } from '../shared/api';
export type FormRequest = (path: string, init?: RequestInit) => Promise<any>;
export class FormRequestError extends Error { constructor(message: string, readonly status: number) { super(message); } }
export function useFormRequest(): FormRequest {
  const { getAccessToken } = useStaffAuth();
  return useCallback(async (path: string, init: RequestInit = {}) => {
    const token = await getAccessToken();
    const response = await fetch(`${apiBaseUrl}${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...init.headers } });
    const body = await response.json();
    if (!response.ok) throw new FormRequestError(apiErrorMessage(body, response.status), response.status);
    if (!body?.data) throw new Error('Invalid form response.');
    return normalizeNumericIds(body.data);
  }, [getAccessToken]);
}
