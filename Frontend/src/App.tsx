import { lazy, Suspense, useEffect, useState } from "react";
import {
  AppBar,
  Box,
  Button,
  Container,
  Drawer,
  Grid,
  IconButton,
  Paper,
  Stack,
  Toolbar,
  Typography,
} from "@mui/material";
import { ArrowUpRight, HeartPulse, Mail, Menu, Phone, X } from "lucide-react";
import { Link, Navigate, Route, Routes, useLocation } from "react-router-dom";
import { useClinicConfig } from "./config/ClinicConfigProvider";
import { portalLink } from "./shared/urls";
import { ClientLoginLink } from "./public/ClientLoginLink";
import { useTranslation } from "react-i18next";
import { LanguageSwitcher } from "./i18n/LanguageSwitcher";
import { TeamSection } from "./public/TeamSection";
import { ServiceDetails, ServicesDirectory } from "./public/Services";
import { PractitionerDetails, PractitionersDirectory } from "./public/Practitioners";
import { BrandLogo } from "./config/BrandLogo";
import { publicNavigation, publicSections, type PublicSection } from "./public/siteLayout";

const ContentSection = lazy(() => import("./content/MarkdownContent").then(module => ({ default: module.ContentSection })));

function scrollToSection(id: string, behavior: ScrollBehavior = 'smooth') {
  const section = document.getElementById(id);
  if (!section) return;
  const headerHeight = document.getElementById('public-header')?.getBoundingClientRect().height ?? 0;
  const top = section.getBoundingClientRect().top + window.scrollY - headerHeight - 12;
  window.scrollTo({ top: Math.max(0, top), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : behavior });
}

function useActiveSection(pathname: string) {
  const [active, setActive] = useState('');
  useEffect(() => {
    if (pathname !== '/') { setActive(''); return; }
    let frame = 0;
    const update = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const headerHeight = document.getElementById('public-header')?.getBoundingClientRect().height ?? 0;
        const current = publicSections.filter(section => section.navLabel && (document.getElementById(section.id)?.getBoundingClientRect().top ?? Infinity) <= headerHeight + 80).at(-1);
        setActive(current?.id ?? '');
      });
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    return () => { cancelAnimationFrame(frame); window.removeEventListener('scroll', update); window.removeEventListener('resize', update); };
  }, [pathname]);
  return active;
}

function PublicSectionContent({ section }: { section: PublicSection }) {
  switch (section.kind) {
    case 'markdown': return <Suspense fallback={null}><ContentSection contentKey={section.contentKey!} presentation={section.presentation} /></Suspense>;
    case 'services': return <ServicesDirectory embedded />;
    case 'practitioners': return <PractitionersDirectory embedded />;
    case 'team': return <TeamSection administrationOnly />;
    case 'contact': return <ContactSection />;
  }
}

function Home() {
  const { t } = useTranslation();
  const location = useLocation();
  const { config } = useClinicConfig();
  useEffect(() => {
    if (!location.hash || location.hash === '#booking') return;
    const id = location.hash.slice(1);
    if (!publicSections.some(section => section.id === id)) return;
    let frame = requestAnimationFrame(() => scrollToSection(id));
    // Earlier API/Markdown sections can grow after a direct deep link loads.
    // Keep the target aligned briefly, but stop immediately if the visitor scrolls.
    const observer = new ResizeObserver(() => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => scrollToSection(id, 'instant'));
    });
    const main = document.getElementById('main-content');
    if (main) observer.observe(main);
    const stop = () => { observer.disconnect(); cancelAnimationFrame(frame); clearTimeout(timeout); window.removeEventListener('wheel', stop); window.removeEventListener('touchstart', stop); window.removeEventListener('keydown', stop); };
    const timeout = window.setTimeout(stop, 5000);
    window.addEventListener('wheel', stop, { passive: true });
    window.addEventListener('touchstart', stop, { passive: true });
    window.addEventListener('keydown', stop);
    return stop;
  }, [location.hash]);
  useEffect(() => {
    document.title = config.name;
    let description = document.querySelector<HTMLMetaElement>('meta[name="description"]');
    if (!description) {
      description = document.createElement('meta');
      description.name = 'description';
      document.head.append(description);
    }
    description.content = t('Explore our services, meet your practitioner, and browse available appointment times.');
  }, [config.name, t]);
  return (
    <><Box className="hero" py={{ xs: 9, md: 15 }}>
      <Container maxWidth="lg">
        <Grid container spacing={5} alignItems="center">
          <Grid size={{ xs: 12, md: 7 }}>
            <Typography variant="overline" color="primary">
              {t("Care that makes room for you")}
            </Typography>
            <Typography
              variant="h1"
              fontSize={{ xs: "2.7rem", md: "4.3rem" }}
              lineHeight={1.08}
            >
              {t("Feel better, on your schedule.")}
            </Typography>
            <Typography color="text.secondary" fontSize="1.2rem" mt={3}>
              {t(
                "Explore our services, meet your practitioner, and browse available appointment times.",
              )}
            </Typography>
            <Button
              href={portalLink()}
              variant="contained"
              size="large"
              sx={{ mt: 4 }}
            >
              {t("FindAnAppointment")}
            </Button>
            <Button component={Link} to="/#services" size="large" endIcon={<ArrowUpRight size={18} />} sx={{ mt: 4, ml: { xs: 0, sm: 2 } }} onClick={() => scrollToSection('services')}>
              {t('Explore services')}
            </Button>
          </Grid>
          <Grid size={{ xs: 12, md: 5 }}>
            <Paper className="hero-note" variant="outlined" sx={{ p: { xs: 3, md: 5 } }}>
              <HeartPulse size={36} color="#176b62" />
              <Typography variant="h5" mt={2}>
                {t("Care starts with a conversation.")}
              </Typography>
              <Typography mt={2} color="text.secondary">
                {t(
                  "Browse availability without signing in. Contact the clinic for help arranging your visit.",
                )}
              </Typography>
              <Button component={Link} to="/#contact" sx={{ mt: 2 }} onClick={() => scrollToSection('contact')}>
                {t("Contact the clinic")}
              </Button>
            </Paper>
          </Grid>
        </Grid>
      </Container>
    </Box>{publicSections.map(section => <Box key={section.id} id={section.id} className={`public-section public-section--${section.surface}`} component="section"><PublicSectionContent section={section} /></Box>)}</>
  );
}
function ContactSection() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  return (
    <Container maxWidth="lg" sx={{ py: { xs: 7, md: 12 } }}><Grid container spacing={{ xs: 4, md: 8 }} alignItems="center">
      <Grid size={{ xs: 12, md: 6 }}>
        <Typography variant="overline" color="primary">{t('Get in touch')}</Typography>
        <Typography variant="h3" component="h2">{t("Contact {{name}}", { name: config.name })}</Typography>
        <Typography color="text.secondary" fontSize="1.1rem" mt={2} maxWidth={500}>{t('Questions about booking or your first visit? We are here to help.')}</Typography>
        <Button href={portalLink()} variant="contained" sx={{ mt: 4 }}>{t('FindAnAppointment')}</Button>
      </Grid>
      <Grid size={{ xs: 12, md: 6 }}><Paper className="contact-card" variant="outlined" sx={{ p: { xs: 3, md: 5 } }}>
        <Stack spacing={3}>
          {config.phone && <Stack direction="row" spacing={2} alignItems="center"><Phone size={22} aria-hidden="true" /><Box><Typography variant="overline">{t('Phone:')}</Typography><Typography><a href={`tel:${config.phone}`}>{config.phone}</a></Typography></Box></Stack>}
          {config.email && <Stack direction="row" spacing={2} alignItems="center"><Mail size={22} aria-hidden="true" /><Box><Typography variant="overline">{t('Email:')}</Typography><Typography><a href={`mailto:${config.email}`}>{config.email}</a></Typography></Box></Stack>}
          {!config.phone && !config.email && <Typography>{t('Online contact details have not been published yet.')}</Typography>}
        </Stack>
      </Paper></Grid>
    </Grid></Container>
  );
}
function LegacyLinks() {
  const location = useLocation();
  useEffect(() => {
    const query = new URLSearchParams(location.search);
    if (location.hash === "#portal" || query.has("portal")) {
      const page = query.get("portal");
      // Only carry a legacy page name; never forward the original query/fragment.
      window.location.replace(
        portalLink(page && /^[a-z]+$/.test(page) ? `?portal=${page}` : ""),
      );
    }
  }, [location]);
  return null;
}
function LegacyClientRedirect() {
  useEffect(() => {
    // Appointment emails sent before the portal split linked to public /client.
    window.location.replace(portalLink("client"));
  }, []);
  return null;
}
export default function App() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  const location = useLocation();
  const activeSection = useActiveSection(location.pathname);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  useEffect(() => setMobileMenuOpen(false), [location.pathname, location.hash]);
  const sectionLink = (section: PublicSection, mobile = false) => <Button
    key={section.id}
    component={Link}
    to={`/#${section.id}`}
    onClick={() => { scrollToSection(section.id); if (mobile) setMobileMenuOpen(false); }}
    aria-current={location.pathname === '/' && activeSection === section.id ? 'location' : undefined}
    className="public-nav-link"
    sx={mobile ? { justifyContent: 'flex-start', py: 1.5 } : undefined}
  >{t(section.navLabel!)}</Button>;
  return (
    <>
      <LegacyLinks />
      <Box component="a" href="#main-content" className="skip-link">
        {t("SkipToContent")}
      </Box>
      <AppBar
        id="public-header"
        position="sticky"
        color="inherit"
        elevation={0}
        sx={{ borderBottom: "1px solid", borderColor: "divider" }}
      >
        <Container maxWidth="lg">
          <Toolbar disableGutters sx={{ gap: { xs: 1, lg: 2 }, minHeight: { xs: 72, lg: 86 } }}>
            <BrandLogo />
            <Typography
              component={Link}
              to="/"
              color="inherit"
              sx={{ textDecoration: "none", flexGrow: 1, display: { xs: 'none', sm: 'block' }, whiteSpace: 'nowrap' }}
              fontWeight={800}
            >
              {config.name}
            </Typography>
            <Stack
              component="nav"
              aria-label={t("Public navigation")}
              direction="row"
              spacing={0}
              sx={{ display: { xs: 'none', lg: 'flex' }, alignItems: 'center' }}
            >
              {publicNavigation.map(section => sectionLink(section))}
            </Stack>
            <Button href={portalLink()} variant="contained" sx={{ display: { xs: 'none', lg: 'inline-flex' }, whiteSpace: 'nowrap' }}>{t('Book online')}</Button>
            <IconButton aria-label={t('Open menu')} aria-controls="public-mobile-navigation" aria-expanded={mobileMenuOpen} onClick={() => setMobileMenuOpen(true)} sx={{ display: { xs: 'inline-flex', lg: 'none' } }}><Menu size={23} /></IconButton>
            <Stack direction="row" spacing={0.5} alignItems="center" sx={{ flexShrink: 0 }}>
              <LanguageSwitcher />
              <ClientLoginLink />
            </Stack>
          </Toolbar>
        </Container>
      </AppBar>
      <Drawer id="public-mobile-navigation" anchor="right" open={mobileMenuOpen} onClose={() => setMobileMenuOpen(false)} PaperProps={{ sx: { width: 'min(85vw, 340px)', p: 3 } }}>
        <Stack direction="row" alignItems="center" justifyContent="space-between" mb={2}><Typography variant="h6">{t('Explore')}</Typography><IconButton aria-label={t('Close menu')} onClick={() => setMobileMenuOpen(false)}><X size={22} /></IconButton></Stack>
        <Button href={portalLink()} variant="contained" onClick={() => setMobileMenuOpen(false)} sx={{ mb: 2 }}>{t('Book online')}</Button>
        <Stack component="nav" aria-label={t('Public navigation')}>{publicNavigation.map(section => sectionLink(section, true))}</Stack>
      </Drawer>
      <Box
        component="main"
        id="main-content"
        tabIndex={-1}
        sx={{ minHeight: "65vh" }}
      >
        <Routes>
          <Route path="/" element={<HomeWithLegacyBooking />} />
          <Route path="/book" element={<PublicBookingRedirect />} />
          <Route path="/services" element={<Navigate to="/#services" replace />} />
          <Route path="/services/:slug" element={<ServiceDetails />} />
          <Route path="/practitioners" element={<Navigate to="/#practitioners" replace />} />
          <Route path="/practitioners/:slug" element={<PractitionerDetails />} />
          <Route path="/about" element={<Navigate to="/#about" replace />} />
          <Route path="/new-clients" element={<Navigate to="/#new-clients" replace />} />
          <Route path="/faq" element={<Navigate to="/#faq" replace />} />
          <Route path="/contact" element={<Navigate to="/#contact" replace />} />
          <Route path="/client" element={<LegacyClientRedirect />} />
          <Route
            path="*"
            element={
              <Container sx={{ py: 6 }}>
                <Typography variant="h4">{t("PageNotFound")}</Typography>
                <Button component={Link} to="/">
                  {t("ReturnHome")}
                </Button>
              </Container>
            }
          />
        </Routes>
      </Box>
      <Box component="footer" py={4} bgcolor="#123b36" color="white">
        <Container maxWidth="lg">
          <Typography fontWeight={800}>{config.name}</Typography>
          <Typography variant="body2" mt={1}>
            {t(
              "For questions about your information or care, please contact the clinic.",
            )}
          </Typography>
          <Stack direction="row" gap={1} flexWrap="wrap" mt={2}>{publicNavigation.map(section => <Button key={section.id} component={Link} to={`/#${section.id}`} color="inherit" size="small" sx={{ justifyContent: 'flex-start' }}>{t(section.navLabel!)}</Button>)}</Stack>
          <Button
            href={portalLink("staff/login")}
            color="inherit"
            size="small"
            sx={{ mt: 1 }}
          >
            {t("StaffSignIn")}
          </Button>
        </Container>
      </Box>
    </>
  );
}
function HomeWithLegacyBooking() {
  const location = useLocation();
  return location.hash === "#booking" ? <PublicBookingRedirect /> : <Home />;
}
function PublicBookingRedirect() {
  const location = useLocation();
  const { t } = useTranslation();
  useEffect(() => {
    const source = new URLSearchParams(location.search);
    const service = source.get('service') ?? '';
    const practitioner = source.get('practitioner_id') ?? '';
    const target = new URL(portalLink(/^[a-z0-9-]+$/.test(service) ? `services/${encodeURIComponent(service)}/book` : 'availability'));
    if (/^\d+$/.test(practitioner)) target.searchParams.set('practitioner_id', practitioner);
    window.location.replace(target.href);
  }, [location.search]);
  return <Container sx={{ py: 8 }}><Typography role="status">{t('Opening appointment availability in the portal…')}</Typography></Container>;
}
