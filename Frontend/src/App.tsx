import { lazy, Suspense, useEffect } from "react";
import {
  AppBar,
  Box,
  Button,
  Container,
  Grid,
  Paper,
  Stack,
  Toolbar,
  Typography,
} from "@mui/material";
import { HeartPulse } from "lucide-react";
import { Link, Route, Routes, useLocation } from "react-router-dom";
import { useClinicConfig } from "./config/ClinicConfigProvider";
import { portalLink } from "./shared/urls";
import { Booking } from "./public/Booking";
import { ClientLoginLink } from "./public/ClientLoginLink";
import { useTranslation } from "react-i18next";
import { LanguageSwitcher } from "./i18n/LanguageSwitcher";
import { TeamSection } from "./public/TeamSection";

const ContentPage = lazy(() => import("./content/MarkdownContent").then(module => ({ default: module.ContentPage })));
const ContentSection = lazy(() => import("./content/MarkdownContent").then(module => ({ default: module.ContentSection })));

function Home() {
  const { t } = useTranslation();
  return (
    <><Box className="hero" py={{ xs: 7, md: 12 }}>
      <Container maxWidth="lg">
        <Grid container spacing={5} alignItems="center">
          <Grid size={{ xs: 12, md: 8 }}>
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
              component={Link}
              to="/book"
              variant="contained"
              size="large"
              sx={{ mt: 4 }}
            >
              {t("FindAnAppointment")}
            </Button>
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <Paper variant="outlined" sx={{ p: 4 }}>
              <HeartPulse size={36} color="#176b62" />
              <Typography variant="h5" mt={2}>
                {t("Care starts with a conversation.")}
              </Typography>
              <Typography mt={2} color="text.secondary">
                {t(
                  "Browse availability without signing in. Contact the clinic for help arranging your visit.",
                )}
              </Typography>
              <Button component={Link} to="/contact" sx={{ mt: 2 }}>
                {t("Contact the clinic")}
              </Button>
            </Paper>
          </Grid>
        </Grid>
      </Container>
    </Box><Suspense fallback={null}><ContentSection contentKey="sections/home-welcome" /></Suspense></>
  );
}
function Contact() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  return (
    <><Container maxWidth="md" sx={{ py: 7 }}>
      <Typography variant="h3" component="h1">
        {t("Contact {{name}}", { name: config.name })}
      </Typography>
      <Stack spacing={2} mt={3}>
        {config.phone && (
          <Typography>
            {t("Phone:")} <a href={`tel:${config.phone}`}>{config.phone}</a>
          </Typography>
        )}
        {config.email && (
          <Typography>
            {t("Email:")} <a href={`mailto:${config.email}`}>{config.email}</a>
          </Typography>
        )}
        {!config.phone && !config.email && (
          <Typography>
            {t("Online contact details have not been published yet.")}
          </Typography>
        )}
      </Stack>
    </Container><TeamSection /></>
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
export default function App() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  return (
    <>
      <LegacyLinks />
      <Box component="a" href="#main-content" className="skip-link">
        {t("SkipToContent")}
      </Box>
      <AppBar
        position="sticky"
        color="inherit"
        elevation={0}
        sx={{ borderBottom: "1px solid", borderColor: "divider" }}
      >
        <Container maxWidth="lg">
          <Toolbar disableGutters sx={{ gap: 2, flexWrap: "wrap", py: 1 }}>
            <HeartPulse color="#176b62" />
            <Typography
              component={Link}
              to="/"
              color="inherit"
              sx={{ textDecoration: "none", flexGrow: 1 }}
              fontWeight={800}
            >
              {config.name}
            </Typography>
            <Stack
              component="nav"
              aria-label={t("Public navigation")}
              direction="row"
              spacing={1}
              flexWrap="wrap"
            >
              <Button component={Link} to="/book">
                {t("Book online")}
              </Button>
              <Button component={Link} to="/new-clients">
                {t("New clients")}
              </Button>
              <Button component={Link} to="/about">
                {t("About")}
              </Button>
              <Button component={Link} to="/faq">
                {t("FAQs")}
              </Button>
              <Button component={Link} to="/contact">
                {t("Contact")}
              </Button>
              <Stack direction="row" spacing={0.5} alignItems="center" sx={{ flexShrink: 0 }}>
                <LanguageSwitcher />
                <ClientLoginLink />
              </Stack>
            </Stack>
          </Toolbar>
        </Container>
      </AppBar>
      <Box
        component="main"
        id="main-content"
        tabIndex={-1}
        sx={{ minHeight: "65vh" }}
      >
        <Routes>
          <Route path="/" element={<HomeWithLegacyBooking />} />
          <Route path="/book" element={<Booking />} />
          <Route path="/about" element={<Suspense fallback={null}><ContentPage contentKey="pages/about" /></Suspense>} />
          <Route path="/new-clients" element={<Suspense fallback={null}><ContentPage contentKey="pages/new-clients" /></Suspense>} />
          <Route path="/faq" element={<Suspense fallback={null}><ContentPage contentKey="pages/faq" /></Suspense>} />
          <Route path="/contact" element={<Contact />} />
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
          <Stack direction="row" spacing={1} flexWrap="wrap" mt={2}>
            <Button component={Link} to="/about" color="inherit" size="small">{t("About")}</Button>
            <Button component={Link} to="/new-clients" color="inherit" size="small">{t("New clients")}</Button>
            <Button component={Link} to="/faq" color="inherit" size="small">{t("FAQs")}</Button>
            <Button component={Link} to="/contact" color="inherit" size="small">{t("Contact")}</Button>
          </Stack>
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
  return location.hash === "#booking" ? <Booking /> : <Home />;
}
