import { Alert, AppBar, Box, Button, Container, Paper, Stack, Toolbar, Typography } from '@mui/material';
import { HeartPulse } from 'lucide-react';
import { Link, Route, Routes } from 'react-router-dom';
import { StaffSignIn } from '../auth/StaffSignIn';
import { UserAccountMenu } from '../auth/UserAccountMenu';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { publicLink } from '../shared/urls';
import { StaffPortal } from './StaffPortal';

function ClientBookingInformation() {
  return <Paper variant="outlined" sx={{ p: { xs: 3, md: 5 }, maxWidth: 700, mx: 'auto' }}>
    <Typography variant="h4" component="h1">Client booking is coming next</Typography>
    <Alert severity="info" sx={{ my: 3 }}>Client sign-in and online confirmation are not available yet. No appointment has been requested or reserved.</Alert>
    <Typography>Google and Microsoft personal-account sign-in are planned. Please contact the clinic to arrange your appointment. Availability will need to be checked again before confirmation.</Typography>
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} mt={3}><Button href={publicLink('contact')} variant="contained">Contact the clinic</Button><Button href={publicLink('book')}>Browse availability</Button></Stack>
  </Paper>;
}
export function PortalApp() {
  const { config } = useClinicConfig();
  return <>
    <Box component="a" href="#main-content" className="skip-link">Skip to content</Box>
    <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: '1px solid', borderColor: 'divider' }}><Container maxWidth="xl"><Toolbar disableGutters sx={{ gap: 2 }}>
      <HeartPulse color="#176b62" style={{ flexShrink: 0 }} /><Typography component={Link} to="/" title={config.name} noWrap color="inherit" sx={{ textDecoration: 'none', flexGrow: 1, minWidth: 0 }} fontWeight={800}>{config.name}</Typography>
      <Button href={publicLink()} size="small">Public website</Button><UserAccountMenu />
    </Toolbar></Container></AppBar>
    <Container component="main" id="main-content" tabIndex={-1} maxWidth="xl" sx={{ py: { xs: 3, md: 5 }, minHeight: '85vh' }}>
      <Routes>
        <Route path="/client/*" element={<ClientBookingInformation />} />
        <Route path="*" element={<StaffSignIn>{roles => <StaffPortal roles={roles} />}</StaffSignIn>} />
      </Routes>
    </Container>
  </>;
}
