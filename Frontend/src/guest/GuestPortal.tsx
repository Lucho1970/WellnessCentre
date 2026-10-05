import { AppBar, Box, Button, Container, Stack, Toolbar, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { Navigate, useLocation } from 'react-router-dom';
import { BrandLogo } from '../config/BrandLogo';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { LanguageSwitcher } from '../i18n/LanguageSwitcher';
import { publicLink } from '../shared/urls';
import { GuestCatalogue } from './GuestCatalogue';
import { GuestPractitionerDetails } from './GuestPractitionerDetails';
import { GuestBooking } from './GuestBooking';

function hasRecentClientSession() {
  try {
    const session = JSON.parse(sessionStorage.getItem('wellness.customer.session.v1') ?? 'null');
    return typeof session?.session_token === 'string' && Math.min(session.idle_expires_at, session.absolute_expires_at) * 1000 > Date.now();
  } catch { return false; }
}

export function GuestPortal() {
  const { t } = useTranslation();
  const { config } = useClinicConfig();
  const location = useLocation();
  const practitionerProfile = /^\/practitioners\/[a-z0-9-]+$/.test(location.pathname);
  const serviceBooking = /^\/services\/[a-z0-9-]+\/book$/.test(location.pathname);
  const bookingPage = serviceBooking || location.pathname === '/availability';
  // Old public-site links targeted the portal root with booking hints. Keep
  // those bookmarks useful without restoring the scheduler to the home page.
  if (location.pathname === '/' && (location.search || location.hash === '#availability')) {
    const params = new URLSearchParams(location.search);
    const service = params.get('service');
    if (service && /^[a-z0-9-]+$/.test(service)) {
      params.delete('service');
      return <Navigate replace to={`/services/${encodeURIComponent(service)}/book${params.size ? `?${params}` : ''}`}/>;
    }
    if (params.has('practitioner_id') || location.hash === '#availability') return <Navigate replace to={`/availability${location.search}`}/>;
  }
  const hasClientSession = hasRecentClientSession();
  return <>
    <Box component="a" href="#main-content" className="skip-link">{t('SkipToContent')}</Box>
    <AppBar position="static" color="inherit" elevation={0} sx={{ borderBottom: '1px solid', borderColor: 'divider' }}><Container maxWidth="xl"><Toolbar disableGutters sx={{ gap: 2, flexWrap: 'wrap', py: 1 }}>
      <BrandLogo/><Typography fontWeight={800} sx={{ flexGrow: 1 }}>{config.name}</Typography>
      <Stack component="nav" aria-label={t('Portal navigation')} direction="row" gap={1} alignItems="center" flexWrap="wrap">
        <Button href={publicLink()} size="small">{t('Public website')}</Button>
        <Button href={import.meta.env.BASE_URL} size="small">{t('Treatments')}</Button>
        <Button href={`${import.meta.env.BASE_URL}client`} size="small">{t('My appointments')}</Button>
        <Button href={`${import.meta.env.BASE_URL}client${hasClientSession ? '' : '?return=browse'}`} variant="contained" size="small">{t(hasClientSession ? 'My account' : 'Log in')}</Button>
        <LanguageSwitcher/>
      </Stack>
    </Toolbar></Container></AppBar>
    {practitionerProfile ? <GuestPractitionerDetails/> : <Box component="main" id="main-content" tabIndex={-1}>{bookingPage ? <GuestBooking/> : <GuestCatalogue/>}</Box>}
    <Box component="footer" sx={{ py: 3, bgcolor: 'primary.dark', color: 'primary.contrastText' }}><Container maxWidth="lg"><Typography fontWeight={700}>{config.name}</Typography><Typography variant="body2">{t('Only available appointment times are shown. A time is not reserved until you confirm your booking.')}</Typography></Container></Box>
  </>;
}
