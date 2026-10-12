import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import { Alert, Button, CssBaseline, Stack, Typography, ThemeProvider, createTheme } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { StaffAuthProvider, msalInstance, selectStaffAccount, useStaffAuth } from '../auth/AuthProvider';
import { UnsavedChangesProvider } from '../shared/UnsavedChanges';
import { ClinicAdmin } from '../admin/ClinicAdmin';
import { apiRequest } from '../shared/api';
import '../i18n';

function Administration() {
  const { t, i18n } = useTranslation();
  const auth = useStaffAuth();
  const [state, setState] = useState<'checking' | 'allowed' | 'denied'>('checking');
  const [error, setError] = useState('');
  useEffect(() => {
    let cancelled = false;
    setState('checking');
    if (!auth.isAuthenticated) return;
    void auth.getAccessToken().then(token => apiRequest('/application/me', { headers: { Authorization: `Bearer ${token}` } })).then(() => { if (!cancelled) setState('allowed'); }).catch(() => { if (!cancelled) setState('denied'); });
    return () => { cancelled = true; };
  }, [auth.isAuthenticated, auth.getAccessToken]);
  const signIn = () => { setError(''); void auth.signIn().catch(() => setError(t('Unable to sign in. Please try again.'))); };
  return <Stack spacing={3} sx={{ maxWidth: 1000, mx: 'auto', p: { xs: 2, md: 4 } }}>
    <Stack direction="row" spacing={2} alignItems="center"><Typography variant="h4" sx={{ flex: 1 }}>{t('Application administration')}</Typography><Button onClick={() => void i18n.changeLanguage(i18n.language === 'fr' ? 'en' : 'fr')}>{i18n.language === 'fr' ? 'EN' : 'FR'}</Button><Button href="/">{t('Portal home')}</Button></Stack>
    {error && <Alert severity="error">{error}</Alert>}
    {!auth.isAuthenticated ? <><Typography>{t('Sign in with your organization Microsoft account. Application administrator access is required.')}</Typography><Button variant="contained" disabled={!auth.configured} onClick={signIn}>{t('Sign in with Microsoft')}</Button>{!auth.configured && <Alert severity="error">{t('Microsoft Entra staff sign-in is not configured.')}</Alert>}</> : <>
      <Button onClick={() => { void auth.signOut().catch(() => setError(t('Unable to sign out. Please try again.'))); }}>{t('SignOut')}</Button>
      {state === 'checking' && <Typography role="status">{t('Checking application access…')}</Typography>}
      {state === 'denied' && <Alert severity="error">{t('Application administration access could not be confirmed. Contact the application owner or try again.')}</Alert>}
      {state === 'allowed' && <ClinicAdmin/>}
    </>}
  </Stack>;
}

async function start() {
  if (window.self !== window.top) return;
  await msalInstance.initialize();
  const result = await msalInstance.handleRedirectPromise();
  msalInstance.setActiveAccount(selectStaffAccount(result?.account ?? msalInstance.getActiveAccount()));
  const router = createBrowserRouter([{ path: '*', element: <UnsavedChangesProvider><Administration/></UnsavedChangesProvider> }]);
  createRoot(document.getElementById('root')!).render(<React.StrictMode><ThemeProvider theme={createTheme({ palette: { primary: { main: '#176b62' } } })}><CssBaseline/><StaffAuthProvider><RouterProvider router={router}/></StaffAuthProvider></ThemeProvider></React.StrictMode>);
}
void start().catch(() => { document.getElementById('root')!.textContent = 'Unable to start administration. Please reload.'; });
