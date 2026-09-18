import { useEffect, useState } from 'react';
import { Alert, AppBar, Avatar, Box, Button, Container, IconButton, Menu, MenuItem, Paper, Stack, Toolbar, Typography } from '@mui/material';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { publicLink } from '../shared/urls';
import { customerConfigured, customerInstance, customerSignIn, customerSignOut } from './auth';
import { customerFetch, clearCustomerSession, updateSessionTimes, type SessionTimes } from './session';
import { CustomerWorkspace, type CustomerStatus } from './CustomerWorkspace';
import { useTranslation } from 'react-i18next';
import { LanguageSwitcher } from '../i18n/LanguageSwitcher';

export function ClientApp({ initialError = '' }: { initialError?: string }) {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  // MSAL returns a new AccountInfo object on each read. Keep a stable snapshot for
  // this page lifetime (sign-in/out navigate away), not an effect dependency loop.
  const [account] = useState(() => customerConfigured ? customerInstance.getActiveAccount() : null);
  const [error, setError] = useState(initialError);
  const [verified, setVerified] = useState(false);
  const [checking, setChecking] = useState(Boolean(account));
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const [status, setStatus] = useState<CustomerStatus | null>(null);
  const [session, setSession] = useState<SessionTimes | null>(null);
  useEffect(() => {
    const end = () => { clearCustomerSession(); setVerified(false); setStatus(null); setSession(null); setChecking(false); setError(t('Your client session has ended. Please sign in again.')); };
    window.addEventListener('customer-session-ended', end);
    const expires = session ? Math.min(session.idle_expires_at, session.absolute_expires_at) * 1000 : null;
    const timer = expires ? window.setTimeout(end, Math.max(0, expires - Date.now())) : undefined;
    let lastActivity = Date.now();
    const activity = (event: Event) => {
      if (!verified || !session || !event.isTrusted || Date.now() - lastActivity < 60000) return;
      lastActivity = Date.now();
      void customerFetch('/auth/activity', { method: 'POST', body: '{}' }).then(data => { updateSessionTimes(data.session); setSession(data.session); }).catch(() => { /* Expiry is still enforced by the server and timer. */ });
    };
    window.addEventListener('pointerdown', activity); window.addEventListener('keydown', activity);
    return () => { window.clearTimeout(timer); window.removeEventListener('customer-session-ended', end); window.removeEventListener('pointerdown', activity); window.removeEventListener('keydown', activity); };
  }, [session, verified, t]);
  useEffect(() => {
    if (!account || initialError) { setChecking(false); return; }
    const controller = new AbortController();
    const end = () => controller.abort();
    window.addEventListener('customer-session-ended', end);
    setVerified(false); setChecking(true); setError('');
    const timeout = window.setTimeout(() => {
      controller.abort(); setChecking(false);
      setError(t('Customer verification timed out. Please retry or sign in again.'));
    }, 20000);
    void (async () => {
      try {
        const data = await customerFetch('/auth/me', { signal: controller.signal });
        if (data?.authenticated !== true || data.authentication_context !== 'customer' || !['not_linked','pending_review','linked'].includes(data.onboarding_status)) {
          throw new Error(t('The server returned an unexpected customer sign-in response. Please contact the clinic.'));
        }
        if (data.session && Math.min(data.session.idle_expires_at, data.session.absolute_expires_at) * 1000 <= Date.now()) throw new Error(t('Your client session has ended. Please sign in again.'));
        if (!controller.signal.aborted) {
          setVerified(true); setStatus(data.session ? data : null); setSession(data.session ?? null);
          if (data.session) updateSessionTimes(data.session);
        }
      } catch (cause) {
        if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to verify customer sign-in.'));
      } finally { window.clearTimeout(timeout); if (!controller.signal.aborted) setChecking(false); }
    })();
    return () => { window.clearTimeout(timeout); controller.abort(); window.removeEventListener('customer-session-ended', end); };
  }, [account, attempt, initialError, t]);

  const run = async (action: () => Promise<void>) => {
    setBusy(true); setError(''); setAnchor(null);
    try { await action(); } catch { setError(t('Unable to complete the account action. Please reload and try again.')); }
    finally { setBusy(false); }
  };
  const name = account?.name || t('Client');
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
  return <>
    <Box component="a" href="#main-content" className="skip-link">{t('Skip to content')}</Box>
    <AppBar position="sticky" color="inherit" elevation={0}><Container maxWidth="xl"><Toolbar disableGutters sx={{ gap: 2 }}>
      <Typography fontWeight={800} sx={{ flexGrow: 1 }}>{config.name}</Typography>
      <LanguageSwitcher /><Button href={publicLink()}>{t('Public website')}</Button>
      <Button href={`${import.meta.env.BASE_URL}staff/login`}>{t('Staff login')}</Button>
      {account && <IconButton aria-label={t('Open client account menu')} onClick={event => setAnchor(event.currentTarget)}><Avatar sx={{ width: 36, height: 36, bgcolor: 'primary.main' }}>{initials}</Avatar></IconButton>}
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
        <MenuItem disabled>{name}</MenuItem>
        <MenuItem disabled={busy} onClick={() => { setVerified(false); void run(customerSignOut); }}>{t('Sign out')}</MenuItem>
      </Menu>
    </Toolbar></Container></AppBar>
    <Container component="main" id="main-content" tabIndex={-1} sx={{ py: 5 }}>
      <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 700, mx: 'auto' }}>
        <Typography variant="h4" component="h1">{t('Client portal')}</Typography>
        {error && <Alert severity="error" sx={{ my: 2 }}>{error}</Alert>}
        {!customerConfigured ? <Alert severity="info" sx={{ my: 2 }}>{t('Client sign-in is not configured yet. Please contact the clinic to arrange your appointment.')}</Alert>
          : checking ? <Typography role="status" sx={{ my: 2 }}>{t('Verifying customer sign-in…')}</Typography>
          : verified ? <Alert severity="success" sx={{ my: 2 }}>{t('Customer sign-in verified.')}</Alert>
          : <Typography sx={{ my: 2 }}>{t('Sign in using Google, your personal Microsoft account, or an email code.')}</Typography>}
        <Alert severity="info" sx={{ my: 2 }}>{t('Client booking is coming next. No appointment has been requested or reserved. Contact the clinic to book or change an appointment.')}</Alert>
        {verified && !status && <Typography>{t('Your sign-in has not been linked to a clinic record. Contact the clinic for assistance. Matching an email address does not grant access to an existing record.')}</Typography>}
        {verified && status && <CustomerWorkspace status={status} onRefresh={() => setAttempt(value => value + 1)} />}
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} mt={3}>
          {customerConfigured && !verified && <Button variant="contained" disabled={busy || checking} onClick={() => void run(customerSignIn)}>{t(account ? 'Sign in again' : 'Sign in or create client account')}</Button>}
          {account && error && <Button disabled={checking || busy} onClick={() => setAttempt(value => value + 1)}>{t('Retry verification')}</Button>}
          <Button href={publicLink('contact')}>{t('Contact the clinic')}</Button>
          <Button href={publicLink('book')}>{t('Browse availability')}</Button>
        </Stack>
      </Paper>
    </Container>
  </>;
}
