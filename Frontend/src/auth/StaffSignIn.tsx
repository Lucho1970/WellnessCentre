import { useEffect, useState, type ReactNode } from 'react';
import { Alert, Button, CircularProgress, Paper, Stack, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { LogIn, ShieldCheck } from 'lucide-react';
import { useStaffAuth } from './AuthProvider';
import { apiRequest } from '../shared/api';

export function StaffSignIn({ children }: { children: (roles: string[]) => ReactNode }) {
  const { account, configured, isAuthenticated, signIn, getAccessToken } = useStaffAuth();
  const accountKey = account?.homeAccountId ?? '';
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [retry, setRetry] = useState(0);
  const [access, setAccess] = useState<{ account: string; roles: string[] } | null>(null);
  useEffect(() => {
    const controller = new AbortController(); setAccess(null); setError('');
    if (!isAuthenticated) return () => controller.abort();
    setBusy(true);
    void getAccessToken().then(token => apiRequest<{ roles: string[] }>('/auth/me', { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal }))
      .then(data => {
        if (!Array.isArray(data.roles) || !data.roles.every(role => typeof role === 'string')) throw new Error('The service returned invalid staff permissions.');
        if (!controller.signal.aborted) setAccess({ account: accountKey, roles: data.roles });
      }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : 'Staff authorization failed.'); })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [getAccessToken, isAuthenticated, accountKey, retry]);
  const login = async () => {
    setBusy(true); setError('');
    try { await signIn(); } catch (cause) { setError(cause instanceof Error ? cause.message : 'Sign-in failed.'); }
    finally { setBusy(false); }
  };
  if (!isAuthenticated) return <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 650, mx: 'auto' }}>
    <Stack spacing={2} alignItems="center" textAlign="center"><ShieldCheck size={44} color="#176b62" />
      <Typography variant="h4" component="h1">Staff portal</Typography>
      <Typography color="text.secondary">Admins, practitioners, reception, and accounting staff sign in with their organization Microsoft account.</Typography>
      {!configured && <Alert severity="warning">Staff sign-in is not configured for this environment. Please contact the administrator.</Alert>}
      {error && <Alert severity="error">{error}</Alert>}
      <Button variant="contained" size="large" startIcon={busy ? <CircularProgress size={18} color="inherit" /> : <LogIn size={18} />} disabled={busy || !configured} onClick={() => void login()}>Sign in with Microsoft</Button>
      <Button component={Link} to="/client">Looking for client booking?</Button>
    </Stack>
  </Paper>;
  if (error) return <Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>Retry</Button>}>{error}</Alert>;
  if (busy || access?.account !== accountKey) return <Stack alignItems="center" py={4}><CircularProgress /><Typography mt={2}>Verifying staff access…</Typography></Stack>;
  if (!access.roles.length) return <Alert severity="warning">This account has no active staff permissions. Please contact the clinic administrator.</Alert>;
  return <div key={accountKey}>{children(access.roles)}</div>;
}
