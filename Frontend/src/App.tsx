import { useEffect } from 'react';
import { AppBar, Box, Button, Container, Grid, Paper, Stack, Toolbar, Typography } from '@mui/material';
import { HeartPulse } from 'lucide-react';
import { Link, Route, Routes, useLocation } from 'react-router-dom';
import { useClinicConfig } from './config/ClinicConfigProvider';
import { portalLink } from './shared/urls';
import { Booking } from './public/Booking';
import { ClientLoginLink } from './public/ClientLoginLink';

function Home() {
  return <Box className="hero" py={{ xs: 7, md: 12 }}><Container maxWidth="lg"><Grid container spacing={5} alignItems="center">
    <Grid size={{ xs: 12, md: 8 }}><Typography variant="overline" color="primary">Care that makes room for you</Typography>
      <Typography variant="h1" fontSize={{ xs: '2.7rem', md: '4.3rem' }} lineHeight={1.08}>Feel better, on your schedule.</Typography>
      <Typography color="text.secondary" fontSize="1.2rem" mt={3}>Explore our services, meet your practitioner, and browse available appointment times.</Typography>
      <Button component={Link} to="/book" variant="contained" size="large" sx={{ mt: 4 }}>Find an appointment</Button>
    </Grid><Grid size={{ xs: 12, md: 4 }}><Paper variant="outlined" sx={{ p: 4 }}><HeartPulse size={36} color="#176b62" />
      <Typography variant="h5" mt={2}>Care starts with a conversation.</Typography>
      <Typography mt={2} color="text.secondary">Browse availability without signing in. Contact the clinic for help arranging your visit.</Typography>
      <Button component={Link} to="/contact" sx={{ mt: 2 }}>Contact the clinic</Button>
    </Paper></Grid>
  </Grid></Container></Box>;
}
function Contact() {
  const { config } = useClinicConfig();
  return <Container maxWidth="md" sx={{ py: 7 }}><Typography variant="h3" component="h1">Contact {config.name}</Typography>
    <Stack spacing={2} mt={3}>
      {config.phone && <Typography>Phone: <a href={`tel:${config.phone}`}>{config.phone}</a></Typography>}
      {config.email && <Typography>Email: <a href={`mailto:${config.email}`}>{config.email}</a></Typography>}
      {!config.phone && !config.email && <Typography>Online contact details have not been published yet.</Typography>}
    </Stack></Container>;
}
function LegacyLinks() {
  const location = useLocation();
  useEffect(() => {
    const query = new URLSearchParams(location.search);
    if (location.hash === '#portal' || query.has('portal')) {
      const page = query.get('portal');
      // Only carry a legacy page name; never forward the original query/fragment.
      window.location.replace(portalLink(page && /^[a-z]+$/.test(page) ? `?portal=${page}` : ''));
    }
  }, [location]);
  return null;
}
export default function App() {
  const { config } = useClinicConfig();
  return <>
    <LegacyLinks />
    <Box component="a" href="#main-content" className="skip-link">Skip to content</Box>
    <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: '1px solid', borderColor: 'divider' }}>
      <Container maxWidth="lg"><Toolbar disableGutters sx={{ gap: 2, flexWrap: 'wrap', py: 1 }}>
        <HeartPulse color="#176b62" /><Typography component={Link} to="/" color="inherit" sx={{ textDecoration: 'none', flexGrow: 1 }} fontWeight={800}>{config.name}</Typography>
        <Stack component="nav" aria-label="Public navigation" direction="row" spacing={1} flexWrap="wrap">
          <Button component={Link} to="/book">Book online</Button><Button component={Link} to="/contact">Contact</Button>
          <ClientLoginLink />
        </Stack>
      </Toolbar></Container>
    </AppBar>
    <Box component="main" id="main-content" tabIndex={-1} sx={{ minHeight: '65vh' }}>
      <Routes><Route path="/" element={<HomeWithLegacyBooking />} /><Route path="/book" element={<Booking />} />
        <Route path="/contact" element={<Contact />} />
        <Route path="*" element={<Container sx={{ py: 6 }}><Typography variant="h4">Page not found</Typography><Button component={Link} to="/">Return home</Button></Container>} />
      </Routes>
    </Box>
    <Box component="footer" py={4} bgcolor="#123b36" color="white"><Container maxWidth="lg"><Typography fontWeight={800}>{config.name}</Typography>
      <Typography variant="body2" mt={1}>For questions about your information or care, please contact the clinic.</Typography>
      <Button href={portalLink('staff/login')} color="inherit" size="small" sx={{ mt: 2 }}>Staff login</Button>
    </Container></Box>
  </>;
}
function HomeWithLegacyBooking() {
  const location = useLocation();
  return location.hash === '#booking' ? <Booking /> : <Home />;
}
