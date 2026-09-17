import { useEffect, useState } from 'react';
import { Alert, AppBar, Avatar, Box, Button, Container, IconButton, Menu, MenuItem, Paper, Stack, Toolbar, Typography } from '@mui/material';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { publicLink } from '../shared/urls';
import { customerConfigured, customerInstance, customerSignIn, customerSignOut, customerToken } from './auth';

export function ClientApp({ initialError = '' }: { initialError?: string }) {
  const { config } = useClinicConfig();
  const account = customerConfigured ? customerInstance.getActiveAccount() : null;
  const [error, setError] = useState(initialError);
  const [verified, setVerified] = useState(false);
  const [checking, setChecking] = useState(Boolean(account));
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  useEffect(() => {
    if (!account || initialError) { setChecking(false); return; }
    const controller = new AbortController();
    setVerified(false); setChecking(true); setError('');
    void (async () => {
      try {
        const token = await customerToken();
        if (controller.signal.aborted) return;
        const response = await fetch(`${import.meta.env.VITE_API_BASE_URL ?? '/api/v1'}/customer/auth/me`, {
          headers: { Authorization: `Bearer ${token}` }, signal: controller.signal, cache: 'no-store', credentials: 'omit',
        });
        const body = await response.json().catch(() => null);
        if (!response.ok) {
          const reference = body?.error?.correlation_id;
          const message = response.status === 401 ? 'Your customer authorization is invalid or expired. Please sign in again.'
            : response.status === 403 ? 'This account does not have the customer API permission. Contact the clinic.'
            : 'The customer sign-in service is unavailable. Please retry later.';
          throw new Error(message + (typeof reference === 'string' ? ` Reference: ${reference}` : ''));
        }
        if (body?.data?.authenticated !== true || body.data.authentication_context !== 'customer' || body.data.onboarding_status !== 'not_linked') {
          throw new Error('The server returned an unexpected customer sign-in response. Please contact the clinic.');
        }
        if (!controller.signal.aborted) setVerified(true);
      } catch (cause) {
        if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : 'Unable to verify customer sign-in.');
      } finally { if (!controller.signal.aborted) setChecking(false); }
    })();
    return () => controller.abort();
  }, [account, attempt, initialError]);

  const run = async (action: () => Promise<void>) => {
    setBusy(true); setError(''); setAnchor(null);
    try { await action(); } catch { setError('Unable to complete the account action. Please reload and try again.'); }
    finally { setBusy(false); }
  };
  const name = account?.name || 'Client';
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
  return <>
    <Box component="a" href="#main-content" className="skip-link">Skip to content</Box>
    <AppBar position="sticky" color="inherit" elevation={0}><Container maxWidth="xl"><Toolbar disableGutters sx={{ gap: 2 }}>
      <Typography fontWeight={800} sx={{ flexGrow: 1 }}>{config.name}</Typography>
      <Button href={publicLink()}>Public website</Button>
      <Button href={new URL(import.meta.env.BASE_URL, window.location.origin).href}>Staff portal</Button>
      {account && <IconButton aria-label="Open client account menu" onClick={event => setAnchor(event.currentTarget)}><Avatar sx={{ width: 36, height: 36, bgcolor: 'primary.main' }}>{initials}</Avatar></IconButton>}
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
        <MenuItem disabled>{name}</MenuItem>
        <MenuItem disabled={busy} onClick={() => { setVerified(false); void run(customerSignOut); }}>Sign out</MenuItem>
      </Menu>
    </Toolbar></Container></AppBar>
    <Container component="main" id="main-content" tabIndex={-1} sx={{ py: 5 }}>
      <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 700, mx: 'auto' }}>
        <Typography variant="h4" component="h1">Client portal</Typography>
        {error && <Alert severity="error" sx={{ my: 2 }}>{error}</Alert>}
        {!customerConfigured ? <Alert severity="info" sx={{ my: 2 }}>Client sign-in is not configured yet. Please contact the clinic to arrange your appointment.</Alert>
          : checking ? <Typography role="status" sx={{ my: 2 }}>Verifying customer sign-in…</Typography>
          : verified ? <Alert severity="success" sx={{ my: 2 }}>Customer sign-in verified.</Alert>
          : <Typography sx={{ my: 2 }}>Sign in using Google, your personal Microsoft account, or an email code.</Typography>}
        <Alert severity="info" sx={{ my: 2 }}>Client booking is coming next. Online confirmation and client records are not available yet. No appointment has been requested or reserved.</Alert>
        {verified && <Typography>Your sign-in has not been linked to a clinic record. Contact the clinic for assistance. Matching an email address does not grant access to an existing record.</Typography>}
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} mt={3}>
          {customerConfigured && !verified && <Button variant="contained" disabled={busy || checking} onClick={() => void run(customerSignIn)}>{account ? 'Sign in again' : 'Sign in or create client account'}</Button>}
          {account && error && <Button disabled={checking || busy} onClick={() => setAttempt(value => value + 1)}>Retry verification</Button>}
          <Button href={publicLink('contact')}>Contact the clinic</Button>
          <Button href={publicLink('book')}>Browse availability</Button>
        </Stack>
      </Paper>
    </Container>
  </>;
}
