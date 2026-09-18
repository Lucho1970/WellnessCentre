import { useEffect, useState, type ReactNode } from 'react';
import { Alert, Button, CircularProgress, Paper, Stack, Typography } from '@mui/material';
import { LogIn, ShieldCheck } from 'lucide-react';
import { useStaffAuth } from './AuthProvider';
import { apiRequest } from '../shared/api';
import { useTranslation } from 'react-i18next';

type StaffAccess = { roles: string[]; permissions: string[] };

export function StaffSignIn({ children }: { children: (access: StaffAccess) => ReactNode }) {
  const { t } = useTranslation();
  const { account, configured, isAuthenticated, signIn, getAccessToken } = useStaffAuth();
  const accountKey = account?.homeAccountId ?? '';
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [retry, setRetry] = useState(0);
  const [access, setAccess] = useState<(StaffAccess & { account: string }) | null>(null);
  useEffect(() => {
    const controller = new AbortController(); setAccess(null); setError('');
    if (!isAuthenticated) return () => controller.abort();
    setBusy(true);
    void getAccessToken().then(token => apiRequest<{ roles: string[]; permissions?: string[] }>('/auth/me', { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal }))
      .then(data => {
        if (!Array.isArray(data.roles) || !data.roles.every(role => typeof role === 'string')) throw new Error(t('The service returned invalid staff permissions.'));
        if (data.permissions !== undefined && (!Array.isArray(data.permissions) || !data.permissions.every(permission => typeof permission === 'string'))) throw new Error(t('The service returned invalid staff permissions.'));
        if (!controller.signal.aborted) setAccess({ account: accountKey, roles: data.roles, permissions: data.permissions ?? [] });
      }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Staff authorization failed.')); })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [getAccessToken, isAuthenticated, accountKey, retry, t]);
  const login = async () => {
    setBusy(true); setError('');
    try { await signIn(); } catch (cause) { setError(cause instanceof Error ? cause.message : t('Sign-in failed.')); }
    finally { setBusy(false); }
  };
  if (!isAuthenticated) return <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 650, mx: 'auto' }}>
    <Stack spacing={2} alignItems="center" textAlign="center"><ShieldCheck size={44} color="#176b62" />
      <Typography variant="h4" component="h1">{t('Staff portal')}</Typography>
      <Typography color="text.secondary">{t('Admins, practitioners, reception, and accounting staff sign in with their organization Microsoft account.')}</Typography>
      {!configured && <Alert severity="warning">{t('Staff sign-in is not configured for this environment. Please contact the administrator.')}</Alert>}
      {error && <Alert severity="error">{error}</Alert>}
      <Button variant="contained" size="large" startIcon={busy ? <CircularProgress size={18} color="inherit" /> : <LogIn size={18} />} disabled={busy || !configured} onClick={() => void login()}>{t('Sign in with Microsoft')}</Button>
      <Button href={`${import.meta.env.BASE_URL}client`}>{t('Client sign in / booking')}</Button>
    </Stack>
  </Paper>;
  if (error) return <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>;
  if (busy || access?.account !== accountKey) return <Stack alignItems="center" py={4}><CircularProgress /><Typography mt={2}>{t('Verifying staff access…')}</Typography></Stack>;
  if (!access.roles.length) return <Alert severity="warning">{t('This account has no active staff permissions. Please contact the clinic administrator.')}</Alert>;
  return <div key={accountKey}>{children({ roles: access.roles, permissions: access.permissions })}</div>;
}
