import { Alert, AppBar, Box, Button, Container, Paper, Stack, Toolbar, Typography } from '@mui/material';
import { HeartPulse } from 'lucide-react';
import { Link, Route, Routes } from 'react-router-dom';
import { StaffSignIn } from '../auth/StaffSignIn';
import { UserAccountMenu } from '../auth/UserAccountMenu';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { publicLink } from '../shared/urls';
import { StaffPortal } from './StaffPortal';
import { useTranslation } from 'react-i18next';
import { LanguageSwitcher } from '../i18n/LanguageSwitcher';

function ClientBookingInformation() {
  const { t } = useTranslation();
  return <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 700, mx: 'auto' }}>
    <Typography variant="h4" component="h1">{t('Client booking is coming next')}</Typography>
    <Alert severity="info" sx={{ my: 3 }}>{t('Client sign-in and online confirmation are not available yet. No appointment has been requested or reserved.')}</Alert>
    <Typography>{t('Google and Microsoft personal-account sign-in are planned. Please contact the clinic to arrange your appointment. Availability will need to be checked again before confirmation.')}</Typography>
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} mt={3}><Button href={publicLink('contact')} variant="contained">{t('Contact the clinic')}</Button><Button href={publicLink('book')}>{t('Browse availability')}</Button></Stack>
  </Paper>;
}
export function PortalApp() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  return <>
    <Box component="a" href="#main-content" className="skip-link">{t('Skip to content')}</Box>
    <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: '1px solid', borderColor: 'divider' }}><Container maxWidth="xl"><Toolbar disableGutters sx={{ gap: 2, flexWrap: 'wrap', py: 1 }}>
      <HeartPulse color="#176b62" style={{ flexShrink: 0 }} /><Typography component={Link} to="/" title={config.name} noWrap color="inherit" sx={{ textDecoration: 'none', flexGrow: 1, minWidth: 0 }} fontWeight={800}>{config.name}</Typography>
      <Stack component="nav" aria-label={t('Portal navigation')} direction="row" spacing={1} flexWrap="wrap" sx={{ width: { xs: '100%', sm: 'auto' }, justifyContent: { xs: 'space-between', sm: 'flex-start' } }}>
        <LanguageSwitcher /><Button href={publicLink()} size="small">{t('Public website')}</Button><UserAccountMenu />
      </Stack>
    </Toolbar></Container></AppBar>
    <Container component="main" id="main-content" tabIndex={-1} maxWidth="xl" sx={{ py: { xs: 3, md: 5 }, minHeight: '85vh' }}>
      <Routes>
        <Route path="/client/*" element={<ClientBookingInformation />} />
        <Route path="*" element={<StaffSignIn>{roles => <StaffPortal roles={roles} />}</StaffSignIn>} />
      </Routes>
    </Container>
  </>;
}
