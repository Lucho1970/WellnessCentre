import { useEffect, useState, type ReactNode } from 'react';
import { Alert, Box, Button, CircularProgress, Paper, Stack, Typography } from '@mui/material';
import { LogIn, LogOut, ShieldCheck } from 'lucide-react';
import { useStaffAuth } from './AuthProvider';

export function StaffSignIn({ children }: { children: (roles: string[]) => ReactNode }) {
  const { account, configured, isAuthenticated, signIn, signOut, getAccessToken } = useStaffAuth();
  const [busy, setBusy] = useState(false); const [error, setError] = useState('');
  const [roles, setRoles] = useState<string[]>([]);
  useEffect(() => {
    if (!isAuthenticated) { setRoles([]); return; }
    let current = true; setBusy(true); setError('');
    getAccessToken().then(token => fetch(`${import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1'}/auth/me`, { headers: { Authorization: `Bearer ${token}` } })).then(async response => { const body = await response.json(); if (!response.ok) throw new Error(body?.error?.message ?? 'Staff authorization failed.'); if (current) setRoles(body.data.roles); }).catch(cause => { if (current) setError(cause instanceof Error ? cause.message : 'Staff authorization failed.'); }).finally(() => { if (current) setBusy(false); });
    return () => { current = false; };
  }, [getAccessToken, isAuthenticated]);
  const run = async (action: () => Promise<void>) => { setBusy(true); setError(''); try { await action(); } catch (cause) { setError(cause instanceof Error ? cause.message : 'Sign-in failed.'); } finally { setBusy(false); } };
  if (!isAuthenticated) return <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 650, mx: 'auto' }}><Stack spacing={2} alignItems="center" textAlign="center"><ShieldCheck size={44} color="#176b62"/><Typography variant="h4">Staff portal</Typography><Typography color="text.secondary">Admins, practitioners, reception, and accounting staff sign in with their organization Microsoft account.</Typography>{!configured && <Alert severity="warning">Copy <code>.env.example</code> to <code>.env.local</code> and add the three Entra application values.</Alert>}{error && <Alert severity="error">{error}</Alert>}<Button variant="contained" size="large" startIcon={busy ? <CircularProgress size={18} color="inherit"/> : <LogIn size={18}/>} disabled={busy || !configured} onClick={() => run(signIn)}>Sign in with Microsoft</Button></Stack></Paper>;
  return <Box><Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} mb={2} spacing={1}><Typography variant="body2" color="text.secondary">Signed in as {account?.name ?? account?.username}{roles.length ? ` · ${roles.join(', ')}` : ''}</Typography><Button size="small" startIcon={<LogOut size={16}/>} onClick={() => run(signOut)} disabled={busy}>Sign out</Button></Stack>{busy && <Stack alignItems="center" py={4}><CircularProgress/><Typography mt={2}>Verifying staff access…</Typography></Stack>}{error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}{roles.length > 0 && children(roles)}</Box>;
}
