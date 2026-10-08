import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from './AuthProvider';
import { apiRequest } from '../shared/api';

type StaffProfile = { id: number; display_name: string; email: string };
type Result = { key: string; profile?: StaffProfile; error?: string };

/** Display the approved application account, not the identity provider's UPN. */
export function useStaffProfile() {
  const { account, isAuthenticated, getAccessToken } = useStaffAuth();
  const { t } = useTranslation();
  const key = isAuthenticated && account ? account.homeAccountId : '';
  const [result, setResult] = useState<Result>();
  useEffect(() => {
    if (!key) return;
    const controller = new AbortController();
    void (async () => {
      try {
        const token = await getAccessToken();
        if (controller.signal.aborted) return;
        const profile = await apiRequest<StaffProfile>('/auth/me', { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal });
        if (!profile || typeof profile.display_name !== 'string' || typeof profile.email !== 'string') throw new Error(t('Account action failed.'));
        if (!controller.signal.aborted) setResult({ key, profile });
      } catch (cause) {
        if (!controller.signal.aborted) setResult({ key, error: cause instanceof Error ? cause.message : t('Account action failed.') });
      }
    })();
    return () => controller.abort();
  }, [key, getAccessToken, t]);
  // Never display details from a previous signed-in account while fetching.
  const current = key && result?.key === key ? result : undefined;
  return { profile: current?.profile, error: current?.error, loading: Boolean(key && !current) };
}
